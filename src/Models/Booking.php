<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Booking extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'bookings';

	protected $hidden = [
        'created_at', 
        'updated_at', 
        'services_id', 
        'services_detail_id'
        ];

	// Reserved, Confirmed, Canceled, Served
	public function services() {
		return $this->hasOne(
			'Models\Services',
			'id',
			'services_id'
		);
	}
	
	public function servicesDetail() {
		return $this->hasOne(
			'Models\ServicesDetails',
			'id',
			'services_detail_id'
		);
	}

	public function recurring() {
		return $this->hasOne(
			'Models\RecurringBookings',
			'booking_id',
			'id'
		);
	}

    public function exceptions() {
        return $this->hasMany(
            'Models\BookingExceptions',
            'booking_id',
            'id'
        );
    }

	public function users() {
		return $this->hasOne(
			'Models\User',
			'id',
			'users_id'
		);
	}
}