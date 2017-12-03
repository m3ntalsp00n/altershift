<?php
header("Access-Control-Allow-Origin: *");
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Models\User;
use Models\Services;
use Models\ServicesDetails;
use Models\Availability;
use Models\Booking;
use Models\RecurringBookings;
use Utils\SaltShaker;
use \Firebase\JWT\JWT;


require '../vendor/autoload.php';



// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);
$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection($settings['settings']['db']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

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
    $user = User::where('email', $data['email'])->get()->first();

    if (SaltShaker::validate($data['password'], $user->password)) {
        $token = genToken($user->id);
        // fix: give user->id own field in 'data'
        return $response->withJson(array('status' => 'success', 'data' => $token, 'message' => $user->id));
    } 

    return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Incorrect username or password'), 400);
});

// todo: add validation
$app->post('/register', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    // check all fields exist
    if (!$data['name'] || !$data['email'] || !$data['password'])
        return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Must have name, email and password'), 400);
    
    if (User::where('email', $data['email'])->count() == 0) {
        $user = User::create(['email' => $data['email'],
                      'name' => $data['name'], 
                      'password' => SaltShaker::shake($data['password'])]);

        // happy days, gen token
        $token = genToken($user->id);

        return $response->withJson(array('status' => 'success', 'data' => $token, 'message' => NULL));
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

function cmp($a, $b){
    return (strtotime($a["start"]) - strtotime($b["start"]));
}

$app->post('/api/getUserBookings', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $_bookings = Booking::
            where('users_id', '=', $data['users_id'])
            ->with('services')
            ->with('servicesDetail')
            ->with('recurring')
            ->get();

    $staff = User::
            where('is_staff', '=', '1')
            ->get();
   
    $bookings = [];
    
    foreach ($_bookings as $book) {
        if ($book['is_recurring']) {
            
            // if there's a recurring booking we'll have to rebuild the list
            // work out to the end of now + 30 days
            $begin = DateTime::createFromFormat('Y-m-d H:i:s', $book['start']); // todo: there'll be issues here, go off 
            // same with end
            $end   = new DateTime(date('Y-m-d H:i:s', strtotime('+ 30 days', $begin->getTimestamp())));  
            for ($i = $begin; $i <= $end; $i->modify('+ 7 day'))
            {
                array_push($bookings, array(
                    "id" => $book["id"],
                    "start" => $i->format('Y-m-d H:i:s'),
                    "finish" => DateTime::createFromFormat('Y-m-d H:i:s', $i->format('Y-m-d') . ' ' . explode(" ", $book['finish'])[1])
                                ->format('Y-m-d H:i:s'),
                    "status" => $book["status"],
                    "users_id" => $book["id"],
                    "services" => $book["services"],
                    "services_detail" => $book["servicesDetail"]
                ));
            }
        } else {
            array_push($bookings, $book);
        }
        usort($bookings, "cmp");
    }

    return $response->withJson(
        array(
            'bookings' => $bookings,
            'staff' => $staff
        )
    );
});

$app->post('/api/getAvailability', function (Request $request, Response $response) {
    $data = json_decode($request->getBody(), true);

    $services = Services::
                with('availability')
                ->with('users')
                ->with('servicesDetail')
                ->where('id', '=', $data['services_id'])
                ->get();

    $bookings = Booking::
                where('services_id', '=', $data['services_id'])
                ->whereBetween('start', array($data['start_date'], $data['end_date']))
                ->with('recurring')
                ->get();

    // get todays date, then + 30 days
    $begin = new DateTime(); // todo: there'll be issues here, go off 
    // $data['start_date'] 
    // same with end
    $end   = new DateTime(date('Y-m-d', strtotime('+ 30 days', $begin->getTimestamp())));
    $capacity = $services[0]['capacity'];

    // rebuild availability into array for quick processing
    $availability = array_fill(0, 7, null);
    foreach ( $services[0]['availability'] as $avail)
    {
        $availability[(int)$avail['repeat_weekday']] = array(
            "start" => $avail['start'],
            "end"   => $avail['end']
        );
    }

    // for each service detail type id...

    $interval = 
            (int)$services[0]['servicesDetail'][0]['time'] 
            + (int)$services[0]['servicesDetail'][0]['break'];

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

        // for each service time, we need to loop and check its times

        // DOW exist in the service availability??
        if ($availability[$dow]) 
        {
            foreach ($availability_list as $key => $inter) {
                $availability_list[$key]['availability'][$i->format('Y-m-d')] = array();
            }
            
            // now iterate through the two times, based on iterval
            // also we need to look for existing bookings
            // if not already booked, add it to the avail list

            // while now < end, + interval

            $timeHours = explode(":", $availability[$dow]['start']);
            $i->setTime($timeHours[0], $timeHours[1]);
            $end_time  = DateTime::createFromFormat('Y-m-d H:i:s', $i->format('Y-m-d') . ' ' . $availability[$dow]['end']);

            while ($i < $end_time)
            {   
                foreach ($availability_list as $key => $inter) {
                    $hit = 0;
                    // got thru every booking and compare start date/times
                    foreach($bookings as $book)
                    {
                        $book_s = DateTime::createFromFormat('Y-m-d H:i:s', $book['start']);
                        $book_e = DateTime::createFromFormat('Y-m-d H:i:s', $book['finish']);

                        // crap, for each different time a service offers,
                        // need to loop through and make sure no other times clash
                        $i_end = clone $i;
                        $i_end->add(new DateInterval('PT' . $availability_list[$key]['time'] . 'M'));

                        if ($i == $book_s && $i_end == $book_e)
                        {
                            // user has already booked, don't allow them to again
                            if ($book['users_id'] == $data['user_id']) {
                                $hit = $capacity;
                                break;
                            }
                        }
                        else if ($i >= $book_s) {
                            if ($i_end <= $book_e) {
                                $hit++;
                                // break;
                            }   
                        }
                        else if ($i >= $book_s && $i_end <= $book_e)
                        {
                            $hit++;
                            // break;
                        }
                        if ($hit >= $capacity)
                        {
                            break;
                        }

                        // for frequency, if no booking clashes
                        // then do booking, add frequency
                        // otherwise, error out, return there's a class
                        if ($book['is_recurring']) {
                            // get the associated recurring booking
                            $recur_book = $book['recurring'];

                            switch ($recur_book['type']) {
                                case 1: // repeat weekly
                                    // get DOW - is it today?
                                    if ($dow == $recur_book['day_of_week']) {
                                        $recur_start = DateTime::createFromFormat('Y-m-d H:i:s', $i->format('Y-m-d') . ' ' .  $recur_book['start']);
                                        $recur_end   = DateTime::createFromFormat('Y-m-d H:i:s', $i->format('Y-m-d') . ' ' .  $recur_book['end']);
                                        // compare times
                                        if ($i == $recur_start &&
                                            $i_end == $recur_end) {
                                                $hit++;
                                            }
                                            else if ($i >= $recur_start) {
                                                if ($i_end <= $recur_end) {
                                                    $hit++;
                                                    // break;
                                                }   
                                            }
                                            else if ($i >= $recur_start && $i_end <= $recur_end)
                                            {
                                                $hit += 1;
                                                // break;
                                            }
                                            if ($hit >= $capacity)
                                            {
                                                break;
                                            }
                                    }
                                    break;
                            }
                        }
                    }

                    
                    
                    // if we have reached capacity
                    if ($hit < $capacity) {
                        array_push($availability_list[$key]['availability'][$i->format('Y-m-d')], $i->format('H:i:s'));
                    }
                    $i->add(new DateInterval('PT' . $inter['time'] . 'M'));
                }
            }
        }
    }    

    return $response->withJson($availability_list);
});

$app->post('/api/makeBooking', function (Request $request, Response $response) {

    $data = json_decode($request->getBody(), true);
    // get token
    // explode off 'Bearer' from the token
    $auth = explode(" ", $request->getHeader('Authorization')[0]);
    $secret = "supersecretkeyyoushouldnotcommittogithub"; // fixme

    $decode = JWT::decode($auth[1], $secret, array("HS256"));

// check detailsssss
// 
// 
//
    // if (!$data['date'])
    //     return $response->withJson(array('status' => 'error', 'data' => null, 'message' => 'Not all booking details present'), 400);

    $service_detail = ServicesDetails::where('id', $data['services_detail_id'])->get()->first();

    $date = new DateTime($data['date']);


    $numBooked = Booking::where('start', $date->format('Y-m-d H:i:s'))->count();
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
    switch ($data['frequency']){
        case 0:
            break;
        case 1: // same time each week
            // + 7 days to date 4 times
            for ($i = 0; $i < 3; $i++) {
                $numBooked = Booking::where('start', $tmp_date->add(new DateInterval('P7D'))->format('Y-m-d H:i:s'))->count();
                if ($numBooked > 0)
                {
                    // confirm booking capacity
                    $service = Services::where('id', $data['services_id'])->get()->first();
                    $cap = $service->capacity;

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


    // if service detail == initial consult, remove ability from user
    if ($service_detail->initial_consult)
    {
        // get user and remove initial consult, check that they can use initial consult
        $user = User::where('id', $decode->jti)->get()->first();
        if ($user->initial_consult) {

        } else {
        }
    }

    $booking = new Booking();
    $booking->users_id = $decode->jti;
    $booking->services_id = $data['services_id'];
    $booking->services_detail_id = $data['services_detail_id'];
    $booking->status = 'C'; // created
    $booking->start = $date->format('Y-m-d H:i:s');
    $date->add(new DateInterval('PT' . ($service_detail->time + $service_detail->break). 'M'));
    $booking->finish = $date->format('Y-m-d H:i:s');

    $booking->is_recurring = $data['frequency'] == 0 ? FALSE : TRUE;

    $booking->save();

    if ($booking->is_recurring == TRUE) {
        // create an entry in recurring_bookings table
        $recurring = new RecurringBookings();
        $recurring->type = $data['frequency'];
        switch ($data['frequency']) {
            case 1: // repeat weekly
                $recurring->day_of_week = $date->format('w');
                break;
            // case 2: // repeat monthly
            //     break;
        }

        $recurring->start = (new DateTime($data['date']))->format('H:i:s');
        $recurring->end = $date->format('H:i:s');
        $recurring->bookings_id = $booking->id;  
        $recurring->save();
    }

    // get start - calculate finish
    return $response->withJson(array('status' => 'success', 'data' => $booking, 'message' => NULL)); 
});



$app->run();
