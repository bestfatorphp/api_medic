<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileNotFoundException extends NotFoundHttpException
{
    public function __construct(string $path)
    {
        parent::__construct("Файл не найден: {$path}");
    }
}
