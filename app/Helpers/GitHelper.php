<?php

namespace App\Helpers;

class GitHelper
{
    public static function getCommitHash()
    {
        return trim(exec('git log --pretty="%h" -n1 HEAD'));
    }
}
