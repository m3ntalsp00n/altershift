<?php 
error_reporting( E_ALL );
ini_set('display_errors', 1);


header("Access-Control-Allow-Origin: *");
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Models\User;
use Models\Services;
use Models\ServicesDetails;
use Models\Availability;
use Models\Booking;
use Models\Cache;
use Models\RecurringBookings;
use Models\BookingExceptions;
use Models\PublicHolidays;
use Models\Timeoff;
use Utils\SaltShaker;
use \Firebase\JWT\JWT;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);
$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection($settings['settings']['db']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

const KEY_URL = "https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com";

function refreshKeys($cache) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, KEY_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    $data = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = trim(substr($data, 0, $header_size));
    $raw_keys = trim(substr($data, $header_size));
    
    if (preg_match('/age:[ ]+?(\d+)/i', $headers, $age_matches) === 1) 
    {
        $age = $age_matches[1];
        if (preg_match('/cache-control:.+?max-age=(\d+)/i', $headers, $max_age_matches) === 1) {
            $valid_for = $max_age_matches[1] - $age;

            $cache->timeout = time() + $valid_for;
            $cache->keys = $raw_keys;
            $cache->save();
        }
    }
}
/**
* Checks whether new keys should be downloaded, and retrieves them, if needed.
*/
function checkKeys()
{
    $cache = Cache::get()->first();

    if (!$cache) {
        $cache = new Cache();
        refreshKeys($cache);
    }
    else {      
        if (time() > $cache->timeout){
            refreshKeys($cache);
        }
    }
}
checkKeys();

// Set up dependencies
require __DIR__ . '/../src/dependencies.php';
// Register middleware
require __DIR__ . '/../src/middleware.php';
// Register routes
require __DIR__ . '/../src/routes.php';

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
    header('Access-Control-Allow-Methods: POST, GET, PUT');
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
}

ob_start('ob_gzhandler');

function genToken($id)
{
    $now = new DateTime();
    $future = new DateTime("now +2 hours");
    $jti = $id;

    $secret = "supersecretkeyyoushouldnotcommittogithub";

    $payload = [
        "jti" => $jti,
        "iat" => $now->getTimeStamp(),
//         "nbf" => $future->getTimeStamp()
//         "exp"
        ];

    return (JWT::encode($payload, $secret, "HS256"));
}

$app->post('/login', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);
    $user = User::where('id', '=', $data['id'])->get()->first();

    if ($user['email'] == $data['email']) {
        return $response->withJson(array(
            'status' => 'success',
            'data' => array(
                'id' => $user['id'],
                'is_staff' => $user['is_staff']
            ),
            'message' => null));     
    }

    return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Incorrect username or password'), 401);
});

// todo: add validation
$app->post('/register', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    // check all fields exist
    if (!$data['name'] || !$data['email'] || !$data['token_id'])//|| !$data['password'])
        return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Must have name, email and token'), 400);
    
    if (User::where('email', $data['email'])->count() == 0) {
        $user = User::create(['email' => $data['email'],
                      'name' => $data['name'], 
                      'id' => $data['token_id']]); // auth handled somewhere else now

                    //   'password' => SaltShaker::shake($data['password'])]);

        // happy days, gen token
        // $token = genToken($user->id);

        return $response->withJson(array('status' => 'success', 
                    'data' => array (
                        'token' => $user->token_id, 
                        'id' => $user->id,
                        'is_staff' => $user->is_staff),
                    'message' => null));
    } else {
        return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'User already exists'), 400);
    }
});

$app->put('/api/updatePassword', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    // explode off 'Bearer' from the token
    $auth = explode(" ", $request->getHeader('Authorization')[0]);
    $secret = "supersecretkeyyoushouldnotcommittogithub";

    $decode = JWT::decode($auth[1], $secret, array("HS256"));

    try {
        $user = User::where('users_id', $decode->jti)->first();
        $user->password = SaltShaker::shake($data['password']);

        $user->save();
    } catch (\Exception $e) {
        return $response->withJson(array('status' => 'error', 'data' => null, 'message' => $e), 400);
    }
    return $response->withJson(array('status' => 'success', 'data' => null, 'message' => NULL));
});

$app->get('/api/services', function (Request $request, Response $response) {
    return $response->withJson(
        Services::
                with('availability')
              ->with('users')
              ->with('servicesDetail')
              ->get()
    );    
});

$app->post('/api/staffServices', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    return $response->withJson(
        Services::
                with('availability')
              ->with('users')
              ->with('servicesDetail')
              ->where('users_id', '=', $data['uid'])
              ->get()
    );    
});

$app->post('/api/staffAvailability', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    return $response->withJson(
        Availability::
              where('availability.users_id', '=', $data['uid'])
              ->get()
    );    
});

function cmp($a, $b){
    return (strtotime($a["start"]) - strtotime($b["start"]));
}

$app->post('/api/getCustomers', function (Request $request, Response $response) {
    return $response->withJson(
        User::
            where('is_staff', '=', 0)
            ->get()
    );
});

$app->post('/api/getStaffBookings', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $_bookings = Booking::
        with('services')
        ->with('users')
        ->where('date', '>=', date('Y-m-d'))
        ->get();

    $bookings = array();

    // from now to a month
    $begin = new DateTime();
    $end = (new DateTime())->modify('+ 4 weeks');

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($begin, $interval, $end);

    foreach ($period as $dt) {
        // for each day, if there are any bookings, we'll add it to the list
        foreach ($_bookings as $book) {
            $date = new DateTime($book['date'] . ' ' . $book['start']);
            
            // fixme --- slow
            if ($book['services']['users_id'] != $data['users_id'])
                continue;

            // test if it is recurring first
            if ($dt->format('Y-m-d') == $date->format('Y-m-d')) {
                $b_date = $date->format('Y-m-d');
                $b_time = $date->format('H:i:s');

                $record_data = [
                    "start"  => $book['start'],
                    "finish" => $book['finish'],
                    "client" => $book['users']['name'],
                    "type"   => $book['services']['name'],
                    "notes"  => $book['notes']
                ];

                $record = 
                    [
                        "date" => $date->format('Y-m-d'),
                        "data" => [$record_data]
                    ];
                // look for existing date
                foreach($bookings as $key => $b) {
                    if (isset($b['date']) && $b['date'] == $date->format('Y-m-d'))
                    {
                        // add to this object
                        array_push($bookings[$key]['data'], $record_data);
                        goto loop;
                        // ??? bail
                    } 
                }

                array_push($bookings, $record);
                loop:;
            }
        }
    }

    return $response->withJson(
        $bookings
    );
});

$app->post('/api/getUserBookings', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $_bookings = Booking::
        where('users_id', '=', $this->jwt->user_id)
        ->with('services')
        ->with('servicesDetail')
        ->with('recurring')
        ->with('exceptions')
        ->where('date', '>=', date('Y-m-d'))
        // ->where('start', '>=', (new DateTime())->modify('+ 1 hour')) // hmmm
        ->get();

    $staff = User::
        where('is_staff', '=', '1')
        ->get();

    $bookings = [];
    
    foreach ($_bookings as $book) {
        switch ($book['status'])
        {
            case 'A': // active
                array_push($bookings, array(
                    "id" => $book['id'],
                    "start" => $book['date'] . ' ' . $book['start'],
                    "finish" => $book['date'] . ' ' . $book['end'],
                    "status" => $book['status'],
                    "users_id" => $book['users_id'],
                    "services" => $book['services'],
                    "services_detail" => $book['servicesDetail']
                ));
                break;
            case 'R': // recurring
                switch ($book['recurring']['type']) 
                {
                    case 1:
                        $txt_dow = date('D', strtotime("Sunday +{$book['recurring']['day_of_week']} days"));
                        $begin = new DateTime("this $txt_dow");

                        $b_explode = explode(":", $book['start']);
                        $begin->setTime($b_explode[0], $b_explode[1], $b_explode[2]);

                        $end = clone $begin;
                        $end->modify('+ 30 days');

                        for ($i = $begin; $i <= $end; $i->modify('+ 7 days'))
                        {
                            $hit_exception = true;
                            // if there are exceptions, compare the date time and don't add
                            foreach ($book['exceptions'] as $exception) {
                                $date = new DateTime($exception['date'] . ' ' . $exception['start']);
                                if ($date == $i) {
                                    $hit_exception = false;
                                    break;
                                }
                            }

                            if ($hit_exception) {
                                array_push($bookings, array(
                                    "id" => $book['id'],
                                    "start" => $i->format('Y-m-d H:i:s'),
                                    "finish" => (new DateTime($i->format('Y-m-d') . ' ' . 
                                        $book['end']))->format('Y-m-d H:i:s'),
                                    "status" => $book['status'],
                                    "users_id" => $book['users_id'],
                                    "services" => $book['services'],
                                    "services_detail" => $book['servicesDetail']
                                ));
                            }
                        }

                        break;
                }
                break;
        }
    }

    usort($bookings, "cmp");

    return $response->withJson(
        array(
            'bookings' => $bookings,
            'staff' => $staff
        )
    );
});

$app->post('/api/timeOff', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    // get public holidays
    $holidays = PublicHolidays::get();

    $timeoff = Timeoff::
        where('users_id', $data['uid'])
        ->get();
    

    return $response->withJson(
        array(
            'publicHolidays' => $holidays,
            'timeoff' => $timeoff
        )
    );
});

$app->post('/api/addTimeoff', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $record = new Timeoff();

    $record->date = $data['date'];
    $record->name = $data['name'];
    $record->users_id = $data['uid'];

    $record->save();

    // remove and send cancelation email to user


    // cancel bookings
    return $response->withJson(array('status' => 'success', 'data' => null, 'message' => null)); 
});

$app->post('/api/removeTimeoff', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $record = Timeoff::where('users_id', '=', $data['uid'])
            ->where('date', '=', $data['date'])
            ->delete();

    return $response->withJson(array('status' => 'success', 'data' => $record, 'message' => null)); 
});

function find_date($public_holidays, $date) {
    foreach ($public_holidays as $holiday) {
        if (strcmp($holiday['date'], $date) == 0)
            return true;
    }
    return false;
}

$app->post('/api/getAvailability', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $services = Services::
                with('availability')
                ->with('users')
                ->with('servicesDetail')
                ->where('id', '=', $data['services_id'])
                ->get();

    $bookings = Booking::
                with('recurring')
                ->with('exceptions')
                ->where('services_id', '=', $data['services_id'])
                ->where('status', '!=', 'C') // active bookings only
                ->whereBetween('start', array($data['start_date'], $data['end_date']))
                ->get();

    $public_holidays = PublicHolidays::get();

    $timeoff = Timeoff::where('users_id', '=', $services[0]['users_id'])
            ->get();

    // get todays date, then + 30 days
    $begin = new DateTime();
    // same with end
    $end = clone $begin;
    $end->modify('+ 30 days');

    $capacity = $services[0]['capacity'];

    // rebuild availability into array for quick processing
    $availability = array_fill(0, 7, null);
    foreach ( $services[0]['availability'] as $avail)
    {
        $availability[(int)$avail['repeat_weekday']] = array(
            "start" => $avail['start'],
            "end"   => $avail['end'],
            "break_start" => $avail['break_start'],
            "break_end" => $avail['break_end']
        );
    }

    // for each service detail type id...

    $availability_list = array();
    foreach ($services[0]['servicesDetail'] as $detail)
    {
        $availability_list[$detail['id']] = array(
             "time" => (int)$detail['time'] + (int)$detail['break'],
             "availability" => array());
    }

    // iterate through each day that's a possible appointment
    for ($i = $begin; $i <= $end; $i->modify('+ 1 day'))
    {
        // i.e. 18-11-2017
        // get the dates, Day Of Week
        $dow = $i->format('w');

        // if the date falls on a public holiday - next!
        if (find_date($public_holidays, $i->format('Y-m-d')))
            continue;

        if (find_date($timeoff, $i->format('Y-m-d')))
            continue;

        // for each service time, we need to loop and check its times

        // DOW exist in the service availability??
        if ($availability[$dow]) 
        {
            $i_formatted = $i->format('Y-m-d');
            // build end list with each date
            // 01-01-2018:
            //      0500:
            //      0600:
            foreach ($availability_list as $key => $inter)
                $availability_list[$key]['availability'][$i_formatted] = array();
            
            $timeHours = explode(":", $availability[$dow]['start']);

            // foreach service type
            foreach ($availability_list as $key => $inter) 
            {
                // reset time to the start availability time
                $i->setTime($timeHours[0], $timeHours[1]);
                $end_time = new DateTime($i_formatted . ' ' . $availability[$dow]['end']);

                $break_start = new DateTime($i_formatted . ' ' . $availability[$dow]['break_start']);
                $break_end = new DateTime($i_formatted . ' ' . $availability[$dow]['break_end']);

                // from start loop to end time, add service running time each loop
                while ($i < $end_time)
                {   
                    // if in break, skip over
                    if ($i >= $break_start && $i < $break_end){
                        
                        $i->add(new DateInterval('PT' . $inter['time'] . 'M'));
                        // skip
                        continue;
                    }

                    $hit = 0;

                    // got thru every booking and compare start date/times

                    // we don't care for recurring bookings in this loop
                    // it can have its own later
                    $i_end = clone $i;
                    $i_end->add(new DateInterval('PT' . $availability_list[$key]['time'] . 'M'));

                    foreach($bookings as $book)
                    {
                        if ($book['status'] == 'A') 
                        {
                            $book_start = new DateTime($book['date'] . ' ' . $book['start']);
                            $book_end = new DateTime($book['date'] . ' ' . $book['end']);

                            // crap, for each different time a service offers,
                            // need to loop through and make sure no other times class
                            if ($i == $book_start && $i_end == $book_end)
                            {
                                // user has already booked, don't allow them to again
                                if ($book['users_id'] == $this->jwt->user_id) {
                                    $hit = $capacity;   
                                    break;
                                } else {
                                    $hit++;
                                }
                            }
                            else if ($i >= $book_start && $i_end <= $book_end) {
                                $hit++;
                                // break;
                            }
                            else if (($i >= $book_start && $i < $book_end) && $i_end >= $book_end)
                            {
                                $hit++;
                                // break;
                            }
                            else if ($i < $book_start && $i_end < $book_end && $i_end > $book_start)
                            {
                                $hit++;
                                // break;
                            }

                            if ($hit >= $capacity)
                            {
                                break;
                            }
                        } 
                        else if ($book['status'] == 'R') 
                        {
                            if ($book['recurring']['type'] == 1) 
                            {
                                if ($dow == $book['recurring']['day_of_week']) 
                                {
                                    $rstart = new DateTime($i_formatted . ' ' . $book['start']);
                                    $rend   = new DateTime($i_formatted . ' ' . $book['end']);
                                    $hit_exception = false;
                                        // if there are exceptions, compare the date time and don't compare
                                    foreach ($book['exceptions'] as $exception) {
                                        $date = new DateTime($exception['date'] . ' ' . $exception['start']);
                                        if ($date == $rstart) {
                                            // goto next_iter;
                                            $hit_exception =true;
                                        }
                                    }

                                    if (!$hit_exception)
                                    {                                            
                                        if ($i == $rstart && $i_end == $rend) {
                                            $hit++;
                                        } else if ($i >= $rstart) {
                                            if ($i_end <= $rend)
                                                $hit++;
                                        } else if ($i >= $rstart && $i_end <= $rend) {
                                            $hit++;
                                        }

                                        if ($hit >= $capacity)
                                        {
                                            break;
                                        }
                                    }
                                }
                            }
                        }   
                        // next_iter:;
                    }

                    // if we have reached capacity
                    if ($hit < $capacity) {
                        array_push($availability_list[$key]['availability'][$i_formatted], $i->format('H:i:s'));
                    }
                    $i->add(new DateInterval('PT' . $inter['time'] . 'M'));
                    // $i->add(new DateInterval('PT15M'));
                }
            }
        }
    }    

    return $response->withJson($availability_list);
});

$app->post('/api/cancelBooking', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    //({"cancelType": type, "booking": item}
    // lookup the booking, if recurring we have more work to do
    $booking = $data['booking'];
    switch ($data['cancelType']) {
        case 0:
            Booking::where('id', $booking['id'])->update(['status' => 'C']);
            break;
        case 1:
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $booking['start']);
            $date_end = DateTime::createFromFormat('Y-m-d H:i:s', $booking['finish']);

            // need to create an exception 
            $exception = new BookingExceptions();
            $exception->booking_id = $booking['id'];

            $exception->date = $date->format('Y-m-d');
            $exception->start = $date->format('H:i:s');
            $exception->end = $date->format('H:i:s');
            $exception->status = 'C';

            $exception->save();
            break;
        case 2:
            Booking::where('id', $booking['id'])->update(['status' => 'C']);
            // remove exception rules
            RecurringBookings::where('booking_id', $booking['id'])->delete();
            BookingExceptions::where('booking_id', $booking['id'])->delete();
            break;
    }

    // // maybe resend email?
    // return $response->withJson(array('status' => 'success', 'data' => null, 'message' => 'Appointment cancelled')); 

    return $response->withJson(array('status' => 'success', 'data' => $data, 'message' => 'Appointment cancelled')); 
});

$app->post('/api/makeBooking', function (Request $request, Response $response) {

    $data = json_decode($request->getBody(), true);
    
    $service_detail = ServicesDetails::where('id', $data['services_detail_id'])->get()->first();

    $date = new DateTime($data['date']);

    $date_print = clone $date;

    $numBooked = Booking::
                    where('date', $date->format('Y-m-d'))
                    ->where('start', $date->format('H:i:s'))
                    ->where('status', 'A')
                    ->count();

    if ($numBooked > 0)
    {
        // confirm booking capacity
        $service = Services::where('id', $data['services_id'])->get()->first();
        $cap = $service->capacity;

        if ($numBooked >= $cap)
            return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Oops, that time is no longer available'), 400); 
    }

    $tmp_date = clone $date;
    // get frequency
    // we'll attempt to book same day for the rest of the month
    // if we cant, we'll fail
     // confirm booking capacity
    $service = Services::where('id', $data['services_id'])->get()->first();
    $cap = $service->capacity;

    switch ($data['frequency']){
        case 1: // same time each week
            // + 7 days to date 4 times // recurring????????????????? FIXME
            for ($i = 0; $i < 3; $i++) 
            {
                $tmp_date->add(new DateInterval('P7D'));
                $numBooked = Booking::where('date', $tmp_date->format('Y-m-d'))
                    ->where('start', $tmp_date->format('H:i:s'))
                    ->where('status', 'A')->count();

                if ($numBooked > 0)
                {
                    if ($numBooked >= $cap)
                        return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Oops, that time cannot be booked weekly'), 400); 
                }
            }

            break;
        // case 2: // same time each month
        //     for ($i = 0; $i < 2; $i++) {
        //         $numBooked = Booking::where('start', $tmp_date->add(new DateInterval('P1M')))->count();

        //         if ($numBooked > 0)
        //         {
        //             // confirm booking capacity
        //             $service = Services::where('id', $data['services_id'])->get()->first();
        //             $cap = $service->capacity;

        //             if ($numBooked >= $cap)
        //                 return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Oops, that time cannot be booked monthly'), 400); 
        //         }
        //     }
        //     break;
    }

    $booking = new Booking();

    if (isset($data['user_id']))
    {
        $booking->users_id = $data['user_id'];
    }
    else 
    {
        $booking->users_id = $this->jwt->user_id;
    }
    
    $booking->services_id = $data['services_id'];
    $booking->services_detail_id = $data['services_detail_id'];
    $start_time = $date->format('H:i:s');
    $booking->start = $date->format('H:i:s');
    $date->add(new DateInterval('PT' . ($service_detail->time + $service_detail->break). 'M'));
    $booking->end = $date->format('H:i:s');
    $booking->date = $date->format('Y-m-d');
    $booking->status = $data['frequency'] > 0 ? 'R' : 'A';
    $booking->notes = $data['notes'];
    $booking->save();

    // create recurring object
    if ($data['frequency'] > 0) {
        $recurring = new RecurringBookings();
        $recurring->booking_id = $booking->id;
        $recurring->type = $data['frequency'];
        switch ($data['frequency']) {
            case 1: // weekly
                $recurring->day_of_week = $date->format('w');
                break;
            case 2:
                break;
        }
        $recurring->save();
    } 

    $service = Services::
        with('users')
        ->where('id', $data['services_id'])->get()->first();

    $mail = new PHPMailer;
    $mail->isSMTP();
    $mail->Host = 'mail.alternature.com.au';
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = "tls"; 
    $mail->Username = 'automated-email@alternature.com.au';
    $mail->Password = 'RocmagBalEf5';


    $mail->setFrom('automated-email@alternature.com.au', 'Mailer');
    if (isset($data['user_id'])) {
            $userdata = User::where('id', '=', $data['user_id'])->get()->first();
    } else {
    $userdata = User::where('id', '=', $this->jwt->user_id)->get()->first();

    }
        $mail->addAddress($userdata['email'], $userdata['name']);
   
    $mail->isHTML(true);
    $mail->Subject = 'Alternature - Booking Confirmation';
    
    if ($booking->status == 'A') {
        $mail->Body = ' <html>
                        <body>
                        <p>
                        Your booking is confirmed. <br/>
                        On ' . $date_print->format('l (d-m-Y)') . ' at <b>' . $date_print->format('g:ia') .
                        '</b> with '. $service['users']['name'] . ' for
                        ' . $service['name'] . '<br/>
                        </p><p>
                        You may cancel up until 12 hours before the booking start time. 
                        For cancellations within this time please contact Alternature.
                        </p>
                        </body>
                        </html>
                        ';
    } else {
        $mail->Body = ' <html>
                        <body>
                        <p>
                        Your recurring booking is confirmed. <br/>
                        On first appointment is ' . $date_print->format('l (d-m-Y)') . ' at <b>' . $date_print->format('g:ia') .
                        '</b> with '. $service['users']['name'] . ' for
                        ' . $service['name'] . '<br/>
                        This appointment will occur every ' . $date_print->format('l') . '
                        </p><p>
                        You may cancel up until 12 hours before the booking start time. 
                        For cancellations within this time please contact Alternature.
                        </p>
                        </body>
                        </html>
                        ';
    }
    if ($mail->send()) {
        return $response->withJson(array('status' => 'success', 'data' => $booking, 'message' => $this->jwt)); 
    } else {
        return $response->withJson(array('status' => 'error', 'data' => 'Failed to confirm booking: ' . $mail->ErrorInfo, 'message' => NULL), 400); 
    }
});



$app->post('/api/updateAvailability', function (Request $request, Response $response) {

    $data = json_decode($request->getBody(), true);

    // going to come in as Mon.startTime
    $error = [];
    // need uid, service_id, availabilities

    if (!$data)
        return $response->withJson(array('status' => 'error', 'data' => 'No data', 'message' => NULL), 400); 

    // iterate through all data and process
    $uid = $data['uid'];
    $serviceId = $data['serviceId'];

    // get all where uid
    $current_avail = Availability::
        with('services')
        ->where(
            'users_id', '=', $uid 
        )->get();

    $output = array(0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []);

    foreach ($data['availability'] as $value) 
    {
        $conflict_record = null;
        $dow = date('w', strtotime($value["name"]));
        if ($value['checked'])
        {            
            $flag_conflict = false;
            $new_start = date('H:m:s', strtotime($value['start']));
            $new_end   = date('H:m:s', strtotime($value['end']));
            $matching_record = null;

            if ($current_avail) {
                foreach ($current_avail as $avail) {
                    if ($avail['repeat_weekday'] == $dow && $flag_conflict == false) {

                        // ignore this dow and this service id
                        if ($avail['services_id'] != $serviceId) {
                            // make sure times don't clash
                            $current_start = date('H:m:s', strtotime($avail['start']));
                            $current_end   = date('H:m:s', strtotime($avail['end']));

                            if ($new_start > $current_start && $new_end < $current_end)
                            {
                                $flag_conflict = true;
                            }
                            else if ($new_start < $current_start && $new_end > $current_start)
                            {
                                $flag_conflict = true;
                            }
                            if ($flag_conflict)
                                $conflict_record = $avail;

                        }  else {
                            $matching_record = $avail;
                        }
                    }
                }
            }
            // if no clash then do update
            if ($flag_conflict == false) {
                // do we need to update this one anyway?
                // compare to existing entry, if no different don't update
                $record = Availability::find($matching_record['id']);

                if (!$record) {
                    // new record
                    $record = new Availability;
                }

                $record->start = $value['start'];
                $record->end = $value['end'];
                $record->break_start = $value['break'];
                $record->break_end = $value['break_end'];
                $record->services_id = $serviceId;
                $record->users_id = $uid;
                $record->repeat_weekday = $dow;

                $record->save();
            } else {

                // error msg
                array_push($error, "Conflict: " . $value['name'] . 
                        " clashes with " . $conflict_record['services']['name'] . " " . date('D', strtotime("Sunday +{$conflict_record['repeat_weekday']} days")) . " start: " . 
                        $conflict_record['start'] . " end: " . $conflict_record['end']);
            }
        } else {
            // check that false values don't exist in the table
            foreach ($current_avail as $avail) {
                if ($avail['services_id'] == $serviceId &&
                    $avail['repeat_weekday'] == $dow) {
                    // remove entry as it's disabled
                    Availability::destroy($avail['id']);
                }
            }
        }
    }

    if (count($error) != 0) {
        return $response->withJson(array('status' => 'error', 'data' => $error ), 400);
    }

    return $response->withJson(array('status' => 'success', 'data' => '' ));
});

$app->run(); 
?>