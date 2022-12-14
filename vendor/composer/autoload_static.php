<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitec3dc12c54fd3b342b618b3a71d79ece
{
    public static $files = array (
        'a142020d309842f394e1a6e283524069' => __DIR__ . '/..' . '/squizlabs/php_codesniffer/autoload.php',
    );

    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'PHPCompatibility\\' => 17,
        ),
        'D' => 
        array (
            'Dealerdirect\\Composer\\Plugin\\Installers\\PHPCodeSniffer\\' => 55,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'PHPCompatibility\\' => 
        array (
            0 => __DIR__ . '/..' . '/phpcompatibility/php-compatibility/PHPCompatibility',
        ),
        'Dealerdirect\\Composer\\Plugin\\Installers\\PHPCodeSniffer\\' => 
        array (
            0 => __DIR__ . '/..' . '/dealerdirect/phpcodesniffer-composer-installer/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitec3dc12c54fd3b342b618b3a71d79ece::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitec3dc12c54fd3b342b618b3a71d79ece::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitec3dc12c54fd3b342b618b3a71d79ece::$classMap;

        }, null, ClassLoader::class);
    }
}
