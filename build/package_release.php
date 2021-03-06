<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

/*
 * Build a release package, this should be run after the new version is tagged; note the tag must match the version string in AppKernel
 * so if the version string is 1.0.0-beta2 then the tag must be 1.0.0-beta2
 */

$baseDir = __DIR__;

// Check if the version is in a branch or tag
$args             = getopt('b::', array('repackage'));
$gitSourceLocation = (isset($args['b'])) ? ' ' : ' tags/';

// We need the version number so get the app kernel
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/AppKernel.php';

$appVersion = AppKernel::MAJOR_VERSION.'.'.AppKernel::MINOR_VERSION.'.'.AppKernel::PATCH_VERSION.AppKernel::EXTRA_VERSION;

// Use branch if applicable otherwise a version tag
$gitSource = (!empty($args['b'])) ? $args['b'] : $appVersion;

if (!isset($args['repackage'])) {
    // Preparation - Remove previous packages
    echo "Preparing environment\n";
    umask(022);
    chdir(__DIR__);

    system('rm -rf packaging');

    // Preparation - Provision packaging space
    mkdir(__DIR__.'/packaging');

    // Grab the system git path so we can process git commands
    ob_start();
    passthru('which git', $systemGit);
    $systemGit = trim(ob_get_clean());

    // Checkout the version tag into the packaging space
    chdir(dirname(__DIR__));
    system($systemGit.' archive '.$gitSource.' | tar -x -C '.__DIR__.'/packaging', $result);

    if ($result !== 0) {
        exit;
    }

    chdir(__DIR__);
    system('cd '.__DIR__.'/packaging && composer install --no-dev --no-scripts --optimize-autoloader && cd ..', $result);
    if ($result !== 0) {
        exit;
    }

    // Generate the bootstrap.php.cache file
    system(__DIR__.'/packaging/vendor/sensio/distribution-bundle/Sensio/Bundle/DistributionBundle/Resources/bin/build_bootstrap.php', $result);
    if ($result !== 0) {
        exit;
    }

    // Compile prod assets
    system('php '.__DIR__.'/packaging/app/console mautic:assets:generate -e prod', $result);
    if ($result !== 0) {
        exit;
    }

    // Common steps
    include_once __DIR__.'/processfiles.php';

    // In this step, we'll compile a list of files that may have been deleted so our update script can remove them
    // First, get a list of git tags
    ob_start();
    passthru($systemGit.' tag -l', $tags);
    $tags = explode("\n", trim(ob_get_clean()));

    // Get the list of modified files from the initial tag
    // TODO - Hardcode this to the 1.0.0 tag when we're there
    ob_start();
    passthru($systemGit.' diff tags/'.$tags[0].$gitSourceLocation.$gitSource.' --name-status', $fileDiff);
    $fileDiff = explode("\n", trim(ob_get_clean()));

    // Only add deleted files to our list; new and modified files will be covered by the archive
    $deletedFiles  = array();
    $modifiedFiles = array();

    // Build an array of paths which we won't ever distro, this is used for the update packages
    $doNotPackage = array(
        '.gitignore',
        'app/phpunit.xml.dist',
        'build',
        'composer.json',
        'composer.lock',
        'Gruntfile.js',
        'index_dev.php',
        'package.json',
        'upgrade.php'
    );

    // Create a flag to check if the vendors changed
    $vendorsChanged = false;

    foreach ($fileDiff as $file) {
        $filename       = substr($file, 2);
        $folderPath     = explode('/', $filename);
        $baseFolderName = $folderPath[0];

        if (!$vendorsChanged && $filename == 'composer.lock') {
            $vendorsChanged = true;
        }

        $doNotPackageFile   = in_array($filename, $doNotPackage);
        $doNotPackageFolder = in_array($baseFolderName, $doNotPackage);

        if ($doNotPackageFile || $doNotPackageFolder) {
            continue;
        }

        if (substr($file, 0, 1) == 'D') {
            $deletedFiles[] = $filename;
        } else {
            $modifiedFiles[$filename] = true;
        }
    }

    // Add our update files to the $modifiedFiles array so they get packaged
    $modifiedFiles['deleted_files.txt'] = true;
    $modifiedFiles['upgrade.php']       = true;

    // Include assets just in case they weren't
    $assetFiles = array(
        'media/css/app.css' => true,
        'media/css/libraries.css' => true,
        'media/js/app.js' => true,
        'media/js/libraries.js' => true,
        'media/js/mautic-form.js' => true
    );
    $modifiedFiles = $modifiedFiles + $assetFiles;

    // Package the vendor folder if the lock changed
    if ($vendorsChanged) {
        $modifiedFiles['vendor/']                 = true;
        $modifiedFiles['app/bootstrap.php.cache'] = true;
    }

    $filePut = array_keys($modifiedFiles);
    sort($filePut);

    // Write our files arrays into text files
    file_put_contents(__DIR__.'/packaging/deleted_files.txt', json_encode($deletedFiles));
    file_put_contents(__DIR__.'/packaging/modified_files.txt', implode("\n", $filePut));
}

// Post-processing - ZIP it up
chdir(__DIR__ . '/packaging');

system("rm -f ../packages/{$appVersion}.zip ../packages/{$appVersion}-update.zip");

echo "Packaging Mautic Full Installation\n";
system('zip -r ../packages/' . $appVersion . '.zip . -x@../excludefiles.txt > /dev/null');

echo "Packaging Mautic Update Package\n";
system('zip -r ../packages/' . $appVersion . '-update.zip -@ < modified_files.txt > /dev/null');