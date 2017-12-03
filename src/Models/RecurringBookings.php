<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class RecurringBookings extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'recurring_bookings';

	protected $hidden = ['created_at', 'updated_at'];
}