<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Timeoff extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'timeoff';

	protected $hidden = ['created_at', 'updated_at'];
}