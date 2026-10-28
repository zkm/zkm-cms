<?php

namespace App\Models;

use CodeIgniter\Model;

class MenuItemModel extends Model
{
    protected $table         = 'menu_items';
    protected $returnType    = 'array';
    protected $allowedFields = ['location', 'label', 'url', 'post_id', 'sort'];
}
