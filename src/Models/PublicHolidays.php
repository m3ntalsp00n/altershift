<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class PublicHolidays extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'public_holidays';

	protected $hidden = ['created_at', 'updated_at'];
}