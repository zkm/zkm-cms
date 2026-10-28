<?php

namespace App\Models;

use CodeIgniter\Model;

class RedirectModel extends Model
{
    protected $table         = 'redirects';
    protected $returnType    = 'array';
    protected $allowedFields = ['from_path', 'to_path', 'code', 'hits'];

    protected $useTimestamps = true;
}
