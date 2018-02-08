<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class BookingExceptions extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'booking_exceptions';

	protected $hidden = ['created_at', 'updated_at'];
}