<?php

namespace Ody\Core\Foundation\Http\Exception;

class UploadedFileErrorException extends \Exception
{

    public static function forUnmovableFile()
    {
        throw new self('Unmovable file.');
    }

    public static function dueToUnwritableTarget(string $dirname)
    {
        throw new self('Unwritable file.');
    }

    public static function dueToStreamUploadError(string $error)
    {
        throw new self('Stream upload error.');
    }
}