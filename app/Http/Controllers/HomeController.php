<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Cartalyst\Stripe\Exception\BadRequestException;
use Cartalyst\Stripe\Exception\UnauthorizedException;
use Cartalyst\Stripe\Exception\InvalidRequestException;
use Cartalyst\Stripe\Exception\NotFoundException;
use Cartalyst\Stripe\Exception\CardErrorException;
use Cartalyst\Stripe\Exception\ServerErrorException;
use Illuminate\Http\Request;
use App\Http\Requests\updateInfoUser;
use App\Http\Requests\updateImageUser;
use App\Http\Requests\updatePassUser;
use App\Http\Requests\repertoirRequest;
use Cartalyst\Stripe\Stripe;
use App\UserRepertoir;
use App\User_info;
use App\User;
use App\Tag;
use App\Instrument;
use App\Ensemble;
use App\Style;
use App\UserTag;
use App\UserStyle;
use App\UserInstrument;
use App\User_image;
use App\User_video;
use App\User_song;
use App\Member;
use App\Phone;
use App\Ask;
use App\Code;
use App\GigOption;
use App\Message;
use Carbon\Carbon;
use Twilio\Rest\Client;
use Hash;
use Auth;
use Mail;
use Storage;
use stdClass;
use Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth', ['except' => 'confirm']);
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (Auth::user()->confirmed == 0) 
        {
            return redirect()->route('user.unconfirmed');
        }
        elseif(Auth::user()->active == 0) 
        {
            return redirect()->route('user.blocked');
        }
        elseif(Auth::user()->type == 'ensemble') 
        {
            return redirect()->route('ensemble.dashboard');
        }
        else
        {    
            $user = Auth::user()->id;
            $IDuser = User::where('id', $user)->firstOrFail();
            $options = $IDuser->gig_option;
            if ($options == null) {
                $save_new_options = new GigOption;
                $save_new_options->user_id = Auth::user()->id;
                $save_new_options->listDay = 'listDay';
                $save_new_options->listWeek = 'listWeek';
                $save_new_options->month = 'month';
                $save_new_options->start = '08:00';
                $save_new_options->end = '22:00';
                $save_new_options->save();
            }
            //Relation many to many TAGS//
            $my_tags = $IDuser->user_tags->pluck('id')->toArray();
            //Relation many to many STYLES//
            $my_styles = $IDuser->user_styles->pluck('id')->toArray();
            //Relation many to many INSTRUMENTS//
            $my_instruments = $IDuser->user_instruments->pluck('id')->toArray();
            //Relation many to many//
            $info = User_info::where('user_id', $user)->firstOrFail();
            $tags = Tag::orderBy('name', 'DES')->pluck('name', 'id');
            $instruments = Instrument::orderBy('name', 'DES')->pluck('name', 'id');
            $styles = Style::orderBy('name', 'DES')->pluck('name', 'id');
            $images = User_image::where('user_id', $user)->orderBy('name', 'DES')->get();
            $songs = User_song::where('user_id', $user)->get();
            $videos = User_video::where('user_id', $user)->get();
            $repertoires = $IDuser->user_repertoires->all();
            $total_repertoires = UserRepertoir::where('user_id', $user)->where('visible', 1)->count();
            $member_request = Member::where('user_id', $user)->get();
            $asks = Ask::orderBy('id', 'DES')->where('user_id', $user)->get();
            $asks_count = Ask::where('user_id', $user)
                             ->where('read', 0)
                             //->where('available', 0)
                             ->count();
            
            $codes = Code::all();
            
            try{
                $phone = Phone::select('phone', 'country', 'country_code', 'confirmed', 'updated_at')->where('user_id', $user)->firstOrFail();
            } catch(ModelNotFoundException $e) {
                $phone = new stdClass();
                $phone->country = 'null';
                $phone->country_code = '';
                $phone->phone = 0;
                $phone->confirmed = 0;
            }

            $update_timestamp = Carbon::parse($phone->updated_at);
            $now_timestamp = Carbon::now();
            $now = Carbon::parse($now_timestamp);
            $minutes_diference = $update_timestamp->diffInMinutes($now);

            $user_update_timestamp = Carbon::parse($IDuser->created_at);
            $user_now_timestamp = Carbon::now();
            $user_now = Carbon::parse($user_now_timestamp);
            $user_days_diference = $user_update_timestamp->diffInDays($now);

            return view('user.dashboard')
                   ->with('info', $info)
                   ->with('tags', $tags)
                   ->with('instruments', $instruments)
                   ->with('styles', $styles)
                   ->with('my_tags', $my_tags)
                   ->with('my_styles', $my_styles)
                   ->with('my_instruments', $my_instruments)
                   ->with('images', $images)
                   ->with('videos', $videos)
                   ->with('songs', $songs)
                   ->with('repertoires', $repertoires)
                   ->with('total_repertoires', $total_repertoires)
                   ->with('member_requests', $member_request)
                   ->with('asks', $asks)
                   ->with('asks_count', $asks_count)
                   ->with('codes', $codes)
                   ->with('phone', $phone)
                   ->with('minutes', $minutes_diference)
                   ->with('user_days', $user_days_diference);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(updateInfoUser $request, $id)
    {
        $user = Auth::user()->id;
        
        $geometry = substr($request->place_geometry, 1, -1);
        $get_geometry_trimed = explode(", ", $geometry);
        $lat = $get_geometry_trimed[0];
        $lng = $get_geometry_trimed[1];

        $address = 'id:'.$request->place_id.'|address:'.$request->place_address.'|lat:'.$lat.'|long:'.$lng;
        
        User_info::where('user_id', $user)
        ->update([
            'first_name'   => $request->first_name,
            'last_name'    => $request->last_name,
            'about'        => $request->about,
            'bio'          => $request->bio,
            // 'phone'        => $request->phone,
            'degree'       => $request->degree,
            'address'      => $address,
            'location'     => $request->location,
            'mile_radious' => $request->mile_radious
        ]);
        return redirect()->route('user.dashboard');
    }

    public function change_email(Request $request)
    {
        $code = str_random(10);
        $token = $code.time();
        $encrypted = Crypt::encryptString($token);
        // $decrypted = Crypt::decryptString($encrypted);
        // $now = date("F j, Y, g:i a", time());
        $user = User::where('id', Auth::user()->id);
        
        $user->update([
            'token'               => $encrypted
        ]);

        if($user->first()->type == 'soloist'){
            $data = [ 
                'email' => $user->first()->email,
                'name'  => $user->first()->info->first_name.' '.$user->first()->info->last_name,
                'token' => '/update/email/'.$encrypted
            ];
        } elseif($user->first()->type == 'ensemble'){
            $data = [ 
                'email' => $user->first()->email,
                'name'  => $user->first()->ensemble->first_name,
                'token' => '/update/email/'.$encrypted
            ];
        }

        Mail::send('email.update_email', $data, function($message) use ($data){
            $message->from('support@bemusical.us');
            $message->to($data['email']);
            $message->subject("Change email");
        });

        return ['status' => 'OK'];
    }

    public function update_email($token)
    {
        try {
            $decrypted = Crypt::decryptString($token);
            $user = User::where('id', Auth::user()->id)->first();
        } catch (DecryptException $e) {
            return view('user.update_email')->with('time', 0)->with('status', 'ERROR');
        }
        if ($token == $user->token) {
            $array_token = str_split($decrypted, 10);
            $created_to_explode = date("n|j|Y|G|i|s|", $array_token[1]);
            $_e = explode('|', $created_to_explode);
            $timestamp_created = Carbon::create($_e[2],$_e[0],$_e[1],$_e[3],$_e[4],$_e[5]);

            $now = Carbon::now();
            $verify_now = Carbon::parse($now);
            $timestamp_difference = $timestamp_created->diffInMinutes($verify_now);

            return view('user.update_email')->with('time', $timestamp_difference)->with('status', 'OK');
        } else {
            return view('user.update_email')->with('time', 0)->with('status', 'ERROR');
        }
    }

    public function updating_email(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'   => 'email|required'
        ])->validate();

        User::where('id', Auth::user()->id)->update([
            'email'               => $request->email,
            'times_email_changed' => User::where('id', Auth::user()->id)->first()->times_email_changed+1,
            'token'               => null,
        ]); 

        return redirect()->route('user.dashboard');     
    }

    public function send_phone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'   => 'digits:10|required',
            'country' => 'required',
        ])->validate();

        $sid = "ACf29b73d8c11a7d9d84656693aac302f5";
        $token = "d340b51f8ff42b20daeb1607d0459713";
        $client = new Client($sid, $token);

        $user = Auth::user()->id;
        $phone = Phone::where('user_id', $user);
        $six_digit_random_number = mt_rand(100000, 999999);

        $exploding_code = explode("|", $request->country);
        $code_country = $exploding_code[0];
        $name_country = $exploding_code[1];
        $phone_number = $code_country.$request->phone;
        
        $message = $client->messages->create(
            $phone_number,
            array(
                'from' => '+16502156754',
                'body' => 'Bemusical code: '.$six_digit_random_number
            )
        );

        $phone->update([
            'phone'        => $request->phone, 
            'country'      => $name_country,
            'country_code' => $code_country,
            'token'        => $six_digit_random_number,
            'times'        => $phone->first()->times+1,
            'message_id'   => $message->sid,
            'times_token'  => 0,
            'updated_at'   => $phone->first()->updated_at,
        ]);

        return redirect()->back();
    }
    public function confirm_phone(Request $request)
    {
        $user = Auth::user()->id;
        $phone = Phone::where('user_id', $user);

        $update_timestamp = Carbon::parse($phone->first()->updated_at);
        $now_timestamp = Carbon::now();
        $now = Carbon::parse($now_timestamp);
        $minutes_diference = $update_timestamp->diffInMinutes($now);

        if ($request->_c_phone == $phone->first()->token && $phone->first()->confirmed == 0) {
            if ($phone->first()->times_token > 3 && $minutes_diference < 15) {
                if ($minutes_diference >= 15) {
                    $phone->update([
                        'times'        => 1,
                        'confirmed'    => 1,
                        'times_token'  => 1,
                    ]);
                }else{
                    return redirect()->back()->withErrors(['_c_phone'=>"Wait 15 minutes out of ".$minutes_diference]);
                }
            }elseif ($phone->first()->times_token <= 3 && $minutes_diference < 15){
                    $phone->update([
                        'times'        => 1,
                        'confirmed'    => 1,
                        'times_token'  => 1,
                    ]);
                    return redirect()->back();
            }else{
                if ($minutes_diference >= 15) {
                    $phone->update([
                        'times'        => 1,
                        'confirmed'    => 1,
                        'times_token'  => 1,
                    ]);
                    return redirect()->back();
                }else{
                    return redirect()->back()->withErrors(['_c_phone'=>"Wait 15 minutes to try again, and ask for another token"]);
                }
            }
        }else{
            if ($phone->first()->times_token < 3) {
                
                $phone->update([
                    'times_token' => $phone->first()->times_token+1,
                    'updated_at'  => $phone->first()->updated_at,
                ]);

                if ($phone->first()->times_token == 1) {
                    return redirect()->back()->withErrors(['_c_phone'=>"This token does not exist"]);
                }elseif ($phone->first()->times_token == 2) {
                    return redirect()->back()->withErrors(['_c_phone'=>"This token does not exist - One more chance"]);
                }elseif ($phone->first()->times_token == 3) {
                    return redirect()->back()->withErrors(['_c_phone'=>"That was your last chance"]);
                }
            }else{
                return redirect()->back()->withErrors(['_c_phone'=>"Wait 15 minutes to try again, and ask for another token"]);
            }
        }
    }

    public function send_code_phone(Request $request)
    {
        $info = [];
        $mensaje_status = '';
        $user = Auth::user()->id;
        $six_digit_random_number = mt_rand(100000, 999999);
        $phone = Phone::where('user_id', $user);
        
        $phone_number = $phone->first()->country_code.$phone->first()->phone;

        $sid = "ACf29b73d8c11a7d9d84656693aac302f5";
        $token = "d340b51f8ff42b20daeb1607d0459713";
        $client = new Client($sid, $token);

        $update_timestamp = Carbon::parse($phone->first()->updated_at);
        $now_timestamp = Carbon::now();
        $now = Carbon::parse($now_timestamp);
        $minutes_diference = $update_timestamp->diffInMinutes($now);

        if ($phone->first()->confirmed == 0) {
            if ($phone->first()->times >= 3) {
                if ($minutes_diference >= 60) {
                    
                    $message = $client->messages->create(
                      $phone_number,
                      array(
                        'from' => '+16502156754',
                        'body' => 'Bemusical code: '.$six_digit_random_number
                      )
                    );

                    $phone->update([
                        'user_id'      => $user,
                        'token'        => $six_digit_random_number,
                        'times'        => 1,
                        'message_id'   => $message->sid,
                        'updated_at'  => $phone->first()->updated_at,
                    ]);
                    if ($phone->first()->times == 1) {
                        $mensaje_status = 'Message already sent - you have one more chance';
                    }
                    elseif ($phone->first()->times == 2) {
                        $mensaje_status = 'Message already sent - this was your last chance';
                    }elseif($phone->first()->times == 0){
                        $mensaje_status = 'Message already sent';
                    }
                } else {
                    $mensaje_status = 'Wait 60 minutes to re-send the message ('.$minutes_diference.')';
                }
            } else {
            
                $message = $client->messages->create(
                  $phone_number,
                  array(
                    'from' => '+16502156754',
                    'body' => 'Bemusical code: '.$six_digit_random_number
                  )
                );

                if ($phone->first()->times == 1) {
                    $mensaje_status = 'Message already sent - you have one more chance';
                }
                elseif ($phone->first()->times == 2) {
                    $mensaje_status = 'Message already sent - this was your last chance';
                }elseif($phone->first()->times == 0){
                    $mensaje_status = 'Message already sent';
                }
                $phone->update([
                    'user_id'      => $user,
                    'token'        => $six_digit_random_number,
                    'times'        => $phone->first()->times+1,
                    'message_id'   => $message->sid,
                    'updated_at'   => $phone->first()->updated_at,
                ]);
            }
        }

        $phone_object = new stdClass();
        $phone_object->status = $mensaje_status;
        $info[] = $phone_object;

        return response()->json(array('info' => $info), 200);
    }

    public function reset_phone(Request $request)
    {
        $user = Auth::user()->id;
        $phone = Phone::where('user_id', $user);
        
        $phone->update([
            'user_id'      => $user, 
            'phone'        => 0,
            'country'      => 'null',
            'country_code' => 'null', 
            'confirmed'    => 0, 
            'token'        => 0, 
            'times'        => 0, 
            'message_id'   => 'null',
            'times_token'  => 0,
        ]);
        return redirect()->back();
    }

    public function updateImage(Request $request, $id)
    {
        $info = [];

        $validator = Validator::make($request->all(), [
            'image' => 'image|required',
        ]);

        if ($validator->fails()) {
            $update_profile_photo_object = new stdClass();
            $update_profile_photo_object->status ='<strong style="color: red;">Select an image</strong>';
            $info[] = $update_profile_photo_object;
            return response()->json(array('info' => $info), 200);
        } else {

            $user = Auth::user()->id;
            if($request->file('image')){
                $file = $request->file('image');
                $name = 'profile_picture_'.time().'-'.$file->getClientOriginalName();
                $name_nice = str_replace(" ","_",$name);
                $path = public_path().'/images/profile';
                $file->move($path, $name_nice); 
            }

            User_info::where('user_id', $user)
            ->update([
                'profile_picture'   => $name_nice
            ]);

            $update_profile_photo_object = new stdClass();
            $update_profile_photo_object->status ='<strong style="color: green;">Updated</strong>';
            $update_profile_photo_object->name = $name_nice;
            $info[] = $update_profile_photo_object;

            return response()->json(array('info' => $info), 200);

        }
    }

    public function destroyImageUser($image)
    {
        $info = [];
        $user = Auth::user()->id;
        $get_name = User_image::select('user_id','name')->where('id', $image)->first();
        if ($get_name->user_id == $user) { 
            User_image::where('user_id', $user)->where('id', $image)->delete();
            $delete_photo_object = new stdClass();
            $get_name_array = explode("|", $get_name->name);
            $delete_photo_object->status = $get_name_array[1].' <strong style="color: red;">deleted successfully</strong>';
            $delete_photo_object->idImg = $image;
            $info[] = $delete_photo_object;
            return response()->json(array('info' => $info), 200);
        } else {
            $delete_photo_object = new stdClass();
            $delete_photo_object->status = 'Action no permitted';
            $info[] = $delete_photo_object;
            return response()->json(array('info' => $info), 200);
        }

    }

    //View for blocking the main user dashboard
    public function unconfirmed()
    {
        $user = Auth::user()->id;
        $info = User::select('email')->where('id', $user)->firstOrFail();

        if (Auth::user()->confirmed == 0) 
        {
            return view('user.unconfirmed')->with('info', $info);
        } 
        else
        {
            return redirect()->route('user.dashboard');
        }
        
    }

    //This function helps to confirm the user when returns from the email to our page
    public function confirm($confirmation_code)
    {
        $user = User::select('id', 'token', 'confirmed', 'type', 'redirected')
                    ->where('token', $confirmation_code)
                    ->first();   

        if(empty($user))
        {
            return redirect()->back()->withErrors(['token'=>"This token does not exist"]);
        }   
        elseif(!empty($user) and $confirmation_code != $user->token) 
        {
            return redirect()->back()->withErrors(['token'=>"This token does not exist"]);
        }
        elseif(!empty($user) and $confirmation_code = $user->token)
        {
            User::where('id', $user->id)
                ->update([
                    'confirmed' => 1,
                    'token' => null,
                    'redirected' => 1
                ]);

            if ($user->type == 'soloist') {

                $info_user = User_info::select('slug')
                                      ->where('user_id', $user->id)
                                      ->first();

                $slug = str_slug($info_user->slug, "-");

                if (Ensemble::where('slug', '=', $slug)->exists() or User_info::where('slug', '=', $slug)->exists()) {
                    for ($i=1; $i; $i++) { 
                        if (!Ensemble::where('slug', '=', $slug.'-'.$i)->exists() and !User_info::where('slug', '=', $slug.'-'.$i)->exists()) {
                            $slug = $slug.'-'.$i;
                            break;
                        }
                    }
                }else{
                    $slug = $slug;
                }

                User_info::where('user_id', $user->id)
                    ->update([
                        'slug' => $slug
                    ]);

                if($user->redirected != 1){
                    return view('layouts.redirect');
                }else{
                    return redirect()->route('user.dashboard');
                }

            }elseif ($user->type == 'ensemble') {
                
                $ensemble = Ensemble::select('slug')
                                      ->where('user_id', $user->id)
                                      ->first();

                $slug = str_slug($ensemble->slug, "-");

                if (Ensemble::where('slug', '=', $slug)->exists() or User_info::where('slug', '=', $slug)->exists()) {
                    
                    for ($i=1; $i; $i++) { 
                        if (!Ensemble::where('slug', '=', $slug.'-'.$i)->exists() and !User_info::where('slug', '=', $slug.'-'.$i)->exists()) {
                            $slug = $slug.'-'.$i;
                            break;
                       }
                    }
                }else{
                    $slug = $slug;
                }

                Ensemble::where('user_id', $user->id)
                        ->update([
                            'slug' => $slug
                        ]);

                if($user->redirected != 1){
                    return view('layouts.redirect');
                }else{
                    return redirect()->route('ensemble.dashboard'); 
                }
            }
        }
        
    }

    public function storeInstruments(Request $request)
    {        
        $instruments = [];
        $user = Auth::user()->id;
        UserInstrument::where('user_id', $user)->delete();
        
        foreach ($request->instruments as $id) 
        {
            $instrument = new UserInstrument($request->all());
            $instrument->user_id = $user;
            $instrument->instrument_id = $id;
            $instrument->save(); 
        }

        $instrument_object = new stdClass();
        $instrument_object->status ='guardado';
        $instrument_object->data = $request->instruments;
        $instruments[] = $instrument_object;
        return response()->json(array('instruments' => $instruments), 200);
    }

    public function storeTags(Request $request)
    {
        $tags = [];
        $user = Auth::user()->id;
        UserTag::where('user_id', $user)->delete();
        
        foreach ($request->tags as $id) 
        {
            $tag = new UserTag($request->all());
            $tag->user_id = $user;
            $tag->tag_id = $id;
            $tag->save(); 
        }

        $tag_object = new stdClass();
        $tag_object->status ='guardado';
        $tag_object->data = $request->tags;
        $tags[] = $tag_object;
        return response()->json(array('tags' => $tags), 200);
    }

    public function storeStyles(Request $request)
    {
        $styles = [];
        $user = Auth::user()->id;
        UserStyle::where('user_id', $user)->delete();
        
        foreach ($request->styles as $id) 
        {
            $style = new UserStyle($request->all());
            $style->user_id = $user;
            $style->style_id = $id;
            $style->save(); 
        }

        $style_object = new stdClass();
        $style_object->status ='guardado';
        $style_object->data = $request->styles;
        $styles[] = $style_object;
        return response()->json(array('styles' => $styles), 200);
    }

    public function storeImages(Request $request)
    {
        $photos = [];

        $validator = Validator::make($request->all(), [
            'photos' => 'array|required',
        ]);

        if ($validator->fails()) {
            $photo_object = new stdClass();
            $photo_object->status ='<strong style="color: red;">Select an image</strong>';
            $photo_object->failed = 'true';
            $photo[] = $photo_object;
            return response()->json(array('files' => $photos), 200);
        } else {

            $imageRules = array(
                'photos' => 'image'
            );

            $user = Auth::user()->id;
            $num_img = User_image::where('user_id', $user)->count();
            
            if ($num_img < 5) {
                //dd('entre al primer filtro');
                $path = public_path().'/images/general';
                foreach ($request->photos as $photo) {
                    $photo = array('photos' => $photo);
                    $imageValidator = Validator::make($photo, $imageRules);
                    if ($imageValidator->fails()) {
                        //dd('esto fallo');
                        $photo_object = new stdClass();
                        $photo_object->status ='<strong style="color: red;">'.$photo['photos']->getClientOriginalName().' is not an image</strong>';
                        $photo_object->failed = 'true';
                        $photos[] = $photo_object;
                        break;
                    } else {
                        //dd($photo['photos']->getClientOriginalName());
                        $filename = 'user_bio_'.time().'|'.$photo['photos']->getClientOriginalName();
                        $photo['photos']->move($path, $filename);

                        $user_photo = new User_image();
                        $user_photo->user_id = $user;
                        $user_photo->name = $filename;
                        $user_photo->save();

                        $new_num_img = User_image::where('user_id', $user)->count();
                        if ($new_num_img < 5) {
                            $photo_object = new stdClass();
                            $photo_object->name = str_replace('photos/', '',$photo['photos']->getClientOriginalName());
                            $photo_object->fileName = $user_photo->name;
                            $photo_object->fileID = $user_photo->id;
                            $photo_object->status = '<strong style="color: green;">Saved successfully</strong>';
                            $photos[] = $photo_object;
                        }else{
                            $photo_object = new stdClass();
                            $photo_object->status = 'You just can add 5 pictures';
                            $photos[] = $photo_object;
                            break;
                        }
                    }
                }
                return response()->json(array('files' => $photos), 200); 
            } else {
                $photo_object = new stdClass();
                $photo_object->status = 'You just can add 5 pictures';
                $photos[] = $photo_object;
                return response()->json(array('files' => $photos), 200);
            }
        }   
    }

    public function blocked()
    {
        if (Auth::user()->active == 0) 
        {
            return view('user.blocked');
        } 
        else
        {
            return redirect()->route('user.dashboard');
        }
    }

    public function updatePassUser(updatePassUser $request, $id)
    {
        $input = $request->all();
        $user = User::find($id);
        if(!Hash::check($input['old_password'], $user->password)){
            return redirect()->back()->withErrors(['old_password'=>"That's not your current password, try again"]);
        }else{
            $user->update([
                'password'   => bcrypt($request->password)
            ]);
        }
        return redirect()->route('user.dashboard');
    }

    public function video(Request $request)
    {
        $videos = [];
        $user = Auth::user()->id;
        $total_videos = User_video::where('user_id', $user)->count();
        if ($total_videos < 5) {

            $video = new User_video($request->all());
            
            //CHECK IF THIS IS A VIDEO FROM YOUTUBE
            if (strpos($request->video, 'youtube') !== false or strpos($request->video, 'youtu.be') !== false) {

                if (strpos($request->video, 'youtu.be') !== false) {
                    //IF CONTAINS YOUTUBE ID SEARCH FOR ID VIDEO
                    $display = explode("/", $request->video);
                    $id_video = end($display);
                    $video->code = $id_video;                
                }elseif (strpos($request->video, 'iframe') !== false) {
                    //IF CONTAINS YOUTUBE ID SEARCH FOR ID VIDEO
                    $display = explode("/embed/", $request->video);
                    $id_video = explode('"', $display[1]);
                    $video->code = $id_video[0];
                }elseif (strpos($request->video, 'www.youtube.com/watch?v') !== false){
                    //IF CONTAINS YOUTUBE ID SEARCH FOR ID VIDEO
                    $display = explode("=", $request->video);
                    $id_video = end($display);
                    $video->code = $id_video;
                }else{
                    //return redirect()->back()->withErrors(['video'=>"That is not an allowed link or video"]);
                    $video_object = new stdClass();
                    $video_object->status = '<strong style="color: red;">That is not an allowed link or video</strong>';
                    $video_object->flag = '0';
                    $videos[] = $video_object;
                    return response()->json(array('videos' => $videos), 200);
                }

                $video->platform = 'youtube';
                $video->user_id = $user;
                $video->save();
            //CHECK IF THIS IS A VIDEO FROM VIMEO
            }elseif (strpos($request->video, 'vimeo') !== false) {
                
                if (strpos($request->video, 'iframe') !== false) {
                    //IF CONTAINS VIMEO ID, SEARCH FOR ID VIDEO
                    $display = explode('</iframe>', $request->video);
                    $display_1 = explode('/video/', $display[0]);
                    $last_link = end($display_1);
                    $id_video = explode('"', $last_link);
                    $video->code = $id_video[0];                
                }elseif(strpos($request->video, 'https://vimeo.com/') !== false){
                    //IF CONTAINS VIMEO ID, SEARCH FOR ID VIDEO
                    $display = explode("/", $request->video);
                    $id_video = end($display);
                    $video->code = $id_video;
                }else{
                    //return redirect()->back()->withErrors(['video'=>"That is not an allowed link or video"]);
                    $video_object = new stdClass();
                    $video_object->status = '<strong style="color: red;">That is not an allowed link or video</strong>';
                    $video_object->flag = '0';
                    $videos[] = $video_object;
                    return response()->json(array('videos' => $videos), 200);
                }    

                $video->platform = 'vimeo';
                $video->user_id = $user;
                $video->save();

            }else{
                //return redirect()->back()->withErrors(['video'=>"That is not an allowed link or video"]);
                $video_object = new stdClass();
                $video_object->status = '<strong style="color: red;">That is not an allowed link or video</strong>';
                $video_object->flag = '0';
                $videos[] = $video_object;
                return response()->json(array('videos' => $videos), 200);
            }
        }else{
            //return redirect()->back()->withErrors(['video'=>"You only can add 5 videos in total"]);
            $video_object = new stdClass();
            $video_object->status = '<strong style="color: red;">You only can add 5 videos in total</strong>';
            $video_object->flag = '0';
            $videos[] = $video_object;
            return response()->json(array('videos' => $videos), 200);
        }
        $video_object = new stdClass();
        $video_object->status = '<strong style="color: green;">Video successfully added</strong>';
        $video_object->flag = '1';
        $video_object->code = $video->code;
        $video_object->platform = $video->platform;
        $video_object->id = $video->id;
        $videos[] = $video_object;
        return response()->json(array('videos' => $videos), 200);
        //return redirect()->route('user.dashboard');
    }

    public function delete_video($id)
    {
        $info = [];
        $video = User_video::find($id);
        if ($video->user_id == Auth::user()->id) {
            $video->delete();
            $delete_song_object = new stdClass();
            $delete_song_object->status = '<strong style="color: red;">video deleted successfully</strong>';
            $delete_song_object->id = $id;
            $info[] = $delete_song_object;
            return response()->json(array('info' => $info), 200);
        } else {
            $delete_video_object = new stdClass();
            $delete_video_object->status = 'Action no permitted';
            $info[] = $delete_video_object;
            return response()->json(array('info' => $info), 200);
        }
    }

    public function song(Request $request)
    {
        $songs = [];
        $user = Auth::user()->id;
        $total_songs = User_song::where('user_id', $user)->count();

        if ($total_songs < 5) {

            $song = new User_song($request->all());
            //CHECK IF THIS IS A VIDEO FROM SPOTIFY
            if (strpos($request->song, 'spotify') !== false){

                if (strpos($request->song, 'open.spotify') !== false) {
                    $display = explode("/track/", $request->song);
                    $id_song = end($display);
                    $song->code = $id_song; 
                }elseif (strpos($request->song, 'spotify:track') !== false) {
                    $display = explode(":", $request->song);
                    $id_song = end($display);
                    $song->code = $id_song;
                }elseif (strpos($request->song, 'embed.spotify.com') !== false) {
                    $display = explode("%3Atrack%3A", $request->song);
                    $id_song = explode('"', $display[1]);
                    $song->code = $id_song[0];
                }else{
                    $song_object = new stdClass();
                    $song_object->status = '<strong style="color: red;">That is not an allowed link or song</strong>';
                    $song_object->flag = '0';
                    $songs[] = $song_object;
                    return response()->json(array('songs' => $songs), 200);
                    //return redirect()->back()->withErrors(['song'=>"Link not allowed"]);
                }
                $song->platform = 'spotify';
                $song->user_id = $user;
                $song->save();

            }elseif (strpos($request->song, 'soundcloud') !== false) {
                
                if (strpos($request->song, 'iframe') !== false) {
                    $display = explode("api.soundcloud.com/tracks/", $request->song);
                    $id_song = explode("&amp;", $display[1]);
                    $song->code = $id_song[0];
                }else {
                    $song_object = new stdClass();
                    $song_object->status = '<strong style="color: red;">That is not an allowed link or song</strong>';
                    $song_object->flag = '0';
                    $songs[] = $song_object;
                    return response()->json(array('songs' => $songs), 200);
                    //return redirect()->back()->withErrors(['song'=>"Link not allowed"]);
                }     
                $song->platform = 'soundcloud';   
                $song->user_id = $user;
                $song->save();

            }else{
                $song_object = new stdClass();
                $song_object->status = '<strong style="color: red;">That is not an allowed link or song</strong>';
                $song_object->flag = '0';
                $songs[] = $song_object;
                return response()->json(array('songs' => $songs), 200);
                //return redirect()->back()->withErrors(['song'=>"That is not an allowed link or song"]);
            }
        }else{
            $song_object = new stdClass();
            $song_object->status = '<strong style="color: red;">You only can add 5 songs in total</strong>';
            $song_object->flag = '0';
            $songs[] = $song_object;
            return response()->json(array('songs' => $songs), 200);
            //return redirect()->back()->withErrors(['song'=>"You only can add 5 songs in total"]);
        }
        $song_object = new stdClass();
        $song_object->status = '<strong style="color: green;">song successfully added</strong>';
        $song_object->flag = '1';
        $song_object->code = $song->code;
        $song_object->platform = $song->platform;
        $song_object->id = $song->id;
        $songs[] = $song_object;
        return response()->json(array('songs' => $songs), 200);
        //return redirect()->route('user.dashboard');
    }


    public function delete_song($id)
    {
        $info = [];
        $song = User_song::find($id);
        if ($song->user_id == Auth::user()->id) {
            $song->delete();
            $delete_song_object = new stdClass();
            $delete_song_object->status = '<strong style="color: red;">song deleted successfully</strong>';
            $delete_song_object->id = $id;
            $info[] = $delete_song_object;
            return response()->json(array('info' => $info), 200);
        } else {
            $delete_song_object = new stdClass();
            $delete_song_object->status = 'Action no permitted';
            $info[] = $delete_song_object;
            return response()->json(array('info' => $info), 200);
        }
    }

    public function repertoir(Request $request)
    {   
        $info = [];
        $validator = Validator::make($request->all(), [
            'composer' => 'required|max:50',
            'work' => 'required|max:50',
        ]);

        if ($validator->fails()) {
            $repertoir_object = new stdClass();
            $repertoir_object->status = '<strong style="color: red;"> 50 is the max number of caracters</strong>';
            $info[] = $repertoir_object;
            return response()->json(array('info' => $info), 200);
        } else {
            $repertoir = new UserRepertoir($request->all());
            $repertoir->user_id = Auth::user()->id;
            $repertoir->repertoire_example = $request->work.' - '.$request->composer;
            $repertoir->visible = 0;
            $repertoir->save();

            $repertoir_count = UserRepertoir::where('user_id', Auth::user()->id)->where('visible', 1)->count();

            $repertoir_object = new stdClass();
            $repertoir_object->status = '<strong style="color: green;">Repertoir "'.$request->work.' - '.$request->composer.'" successfully added</strong>';
            $repertoir_object->name = $request->work.' - '.$request->composer;
            $repertoir_object->id = $repertoir->id;
            $repertoir_object->count = $repertoir_count;
            $info[] = $repertoir_object;
            return response()->json(array('info' => $info), 200);
        }
    }

    public function destroy_repertoir($id)
    {
        $info = [];
        $repertoir = UserRepertoir::find($id);
        if ($repertoir->user_id == Auth::user()->id) {
            $repertoir->delete();
            $delete_repertoir_object = new stdClass();
            $delete_repertoir_object->status = '<strong style="color: red;">Repertoir deleted successfully</strong>';
            $delete_repertoir_object->id = $id;
            $info[] = $delete_repertoir_object;
            return response()->json(array('info' => $info), 200);
        } else {
            $delete_repertoir_object = new stdClass();
            $delete_repertoir_object->status = 'Action no permitted';
            $info[] = $delete_repertoir_object;
            return response()->json(array('info' => $info), 200);
        }
    }

    public function update_repertoir($id)
    {
        $repertoir = UserRepertoir::select('visible')->find($id);
        $repertoir->visible = !$repertoir->visible;
        UserRepertoir::find($id)->update(['visible' => $repertoir->visible]);
        return redirect()->route('user.dashboard');
    }

    public function ask_review($id)
    {
        User::where('id', $id)->update(['ask_review' => 1]);
        return redirect()->route('user.dashboard');
    }

    public function details_request($id)
    {
        $user = Auth::user()->id;
        $ask = Ask::where('id', $id)->where('user_id', $user)->first();
        if (empty($ask)) {
            return redirect()->back();
        }else{
            if ($ask->read == 0) {
                Ask::where('id', $id)
                    ->update([
                        'read' => 1,
                    ]);
            }
            return view('user.details')->with('request', $ask);
        }
    }

    public function payments()
    {
        $payments = Ask::where('accepted_price', 1)->where('available', 1)->whereNotNull('price')->where('user_id', Auth::user()->id)->orderBy('date', 'desc')->get();
        
        return view('user.payments')
            ->with('payments', $payments);
    }

    public function payouts()
    {
        // $stripe = new Stripe('sk_test_e7FsM5lCe5UwmUEB4djNWmtz');

        // $customer = $stripe->customers()->create([
        //     'email' => 'john@doe.com',
        // ]);

        // $token = $stripe->tokens()->create([
        //     'card' => [
        //         'number'    => '4242424242424242',
        //         'exp_month' => 10,
        //         'cvc'       => 314,
        //         'exp_year'  => 2020,
        //     ],
        // ]);

        // $card = $stripe->cards()->create($customer['id'], $token['id']);


        // // $balance = $stripe->balance()->current();

        // // \Stripe\Stripe::setApiKey("sk_test_e7FsM5lCe5UwmUEB4djNWmtz");

        // // \Stripe\Payout::create(array(
        // //     "amount" => 1000,
        // //     "currency" => "usd",
        // // ), array("stripe_account" => CONNECTED_STRIPE_ACCOUNT_ID));
        // \Stripe\Stripe::setApiKey('sk_test_e7FsM5lCe5UwmUEB4djNWmtz');
        // \Stripe\Payout::create(array(
        //     "amount" => 1000,
        //     "currency" => "usd",
        // ), array("stripe_account" => 'cus_Bgr5zOroQR68lr'));

        // dd($account);
        // dd($customer, $card, $balance);


        \Stripe\Stripe::setApiKey("sk_test_e7FsM5lCe5UwmUEB4djNWmtz");

        $acct = \Stripe\Account::create(array(
            "country" => "US",
            "type" => "custom"
        ));

        $card = \Stripe\Token::create(array(
            "card" => array(
                "currency" => "usd",
                "number" => "4000056655665556",
                "exp_month" => 12,
                "exp_year" => 2018,
                "cvc" => "314"
            )
        ));

        $account = \Stripe\Account::retrieve($acct['id']);

        $account->external_accounts->create(array("external_account" => $card['id']));
        $account->legal_entity->dob->day = '01';
        $account->legal_entity->dob->month = '12';
        $account->legal_entity->dob->year = '1991';
        $account->legal_entity->first_name = 'carlos';
        $account->legal_entity->last_name = 'mendez';
        $account->legal_entity->type = 'individual';
        $account->save();

        // $payout = \Stripe\Payout::create(array(
        //         "amount" => 1000,
        //         "currency" => "usd",
        //         "method" => "instant"
        //     ),
        //     array("stripe_account" => $account['id'])
        // );
        // $account->external_accounts->create(array("external_account" => "tok_visa"));

        // $account = \Stripe\Account::retrieve($acct['id']);
        // $account->external_accounts->create(array(
        //     "external_account" => "btok_9CUINZPUJnubtQ",
        // ));

        // $payout = \Stripe\Payout::create(array(
        //         "amount" => 1000,
        //         "currency" => "usd",
        //         "method" => "instant"
        //     ),
        //     array("stripe_account" => $acct['id'])
        // );

        dd($account);
        return view('layouts.payouts');
    }
}
