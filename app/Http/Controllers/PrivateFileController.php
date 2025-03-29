<?php

namespace App\Http\Controllers;

use App\Exceptions\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileController extends Controller
{
    /**
     * Отдаём файл из приватной папки
     * @param $path
     * @return StreamedResponse
     */
    public function streamFile($path): StreamedResponse
    {
        if (!Storage::disk('private')->exists($path)) {
            throw new FileNotFoundException($path);
        }

        //todo: вощможно нужно будет переделать под огромные файлы
        return Storage::disk('private')->download($path);
    }
}
