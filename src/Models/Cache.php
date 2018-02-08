<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Cache extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'cache';

	protected $hidden = ['id'];

}