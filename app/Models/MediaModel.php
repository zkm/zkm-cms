<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table         = 'media';
    protected $returnType    = 'array';
    protected $allowedFields = ['path', 'mime', 'width', 'height', 'bytes', 'alt', 'caption', 'sizes', 'wp_id'];

    protected $useTimestamps = true;
}
