<?php

namespace App\Controllers;

use Base;
use App\Services\AuthService;
use App\Services\ValidationService;

class BaseController
{
    protected Base $f3;
    protected AuthService $auth;
    protected ValidationService $validator;

    public function beforeRoute(Base $f3): void
    {
        $this->f3 = $f3;
        $this->auth = new AuthService($f3);
        $this->validator = new ValidationService();
        
        $this->auth->checkAuth();
    }
}
