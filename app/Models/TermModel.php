<?php

namespace App\Models;

use CodeIgniter\Model;

class TermModel extends Model
{
    protected $table         = 'terms';
    protected $returnType    = 'array';
    protected $allowedFields = ['taxonomy', 'name', 'slug', 'description', 'parent_id', 'wp_id'];

    protected $useTimestamps = true;
}
