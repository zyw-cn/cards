<?php
namespace App\Utils;

class Captcha
{
    public function create()
    {
        $data = \TgkwAdc\HyperfCaptcha\captcha_create();
        return $data;
    }

    public function verify($code, $key)
    {
        return \TgkwAdc\HyperfCaptcha\captcha_verify($code, $key);
    }
}