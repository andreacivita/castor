<?php

namespace filesystem;

use Castor\Attribute\AsTask;
use Symfony\Component\Filesystem\Path;

use function Castor\fs;

#[AsTask(description: 'Performs some operations on the filesystem')]
function filesystem()
{
    $fs = fs();

    $dir = '/tmp/foo';

    echo $dir, ' directory exist: ', $fs->exists($dir) ? 'yes' : 'no', \PHP_EOL;

    $fs->mkdir($dir);
    $fs->touch($dir . '/bar.md');

    echo $dir, ' is an absolute path: ', Path::isAbsolute($dir) ? 'yes' : 'no', \PHP_EOL;
    echo '../ is an absolute path: ', Path::isAbsolute('../') ? 'yes' : 'no', \PHP_EOL;

    $fs->remove($dir);

    echo 'Absolute path: ', Path::makeAbsolute('../', $dir), \PHP_EOL;
}
