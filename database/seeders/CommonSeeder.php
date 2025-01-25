<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use App\Models\{ActionMT,
    ActivityMT,
    CommonDatabase,
    Doctor,
    ParsingPD,
    Pharma,
    UnisenderContact,
    UnisenderCampaign,
    UnisenderParticipation,
    UserMT};

class CommonSeeder extends Seeder
{
    private const QUANTITY = 5;

    public function run()
    {
        $faker = Faker::create();

        //Seed MT Users
        foreach (range(1, self::QUANTITY) as $index) {
            UserMT::create([
                'full_name' => $faker->name,
                'email' => $faker->unique()->safeEmail,
                'gender' => $faker->randomElement(['male', 'female']),
                'birth_date' => $faker->date,
                'specialty' => $faker->jobTitle,
                'interests' => $faker->sentence,
                'phone' => $faker->phoneNumber,
                'place_of_employment' => $faker->company,
                'registration_date' => $faker->date,
                'country' => $faker->country,
                'region' => "Region $index",
                'city' => $faker->city,
                'registration_website' => $faker->url,
                'acquisition_tool' => "some tool $index",
                'acquisition_method' => "some method $index",
                'uf_utm_term' => "uf_utm_term_$index",
                'uf_utm_campaign' => "uf_utm_campaign$index",
                'uf_utm_content' => "uf_utm_content$index",
            ]);
        }

        //Seed Common Database MT Users info
        foreach (UserMT::all() as $index => $mtUser) {
            CommonDatabase::create([
                'email' => $mtUser->email,
                'full_name' => $mtUser->full_name,
                'city' => $mtUser->city,
                'region' => $mtUser->region,
                'country' => $mtUser->country,
                'specialty' => $mtUser->specialty,
                'interests' => $mtUser->interests,
                'phone' => $mtUser->phone,
                'mt_user_id' => $mtUser->id,
                'registration_date' => $mtUser->registration_date,
                'gender' => $mtUser->gender,
                'birth_date' => $mtUser->birth_date,
                'registration_website' => $mtUser->registration_website,
                'acquisition_tool' => $mtUser->acquisition_tool,
                'acquisition_method' => $mtUser->acquisition_method,
                'planned_actions' => $index,
                'resulting_actions' => $index,
                'verification_status' => "ok$index",
                'pharma' => $faker->boolean,
                'email_status' => $faker->boolean ? 'yes' : 'no',
            ]);
        }

        //Seed Doctors
        foreach (range(1, self::QUANTITY) as $index) {
            Doctor::create([
                'email' => $faker->unique()->safeEmail,
                'full_name' => $faker->name,
                'city' => $faker->city,
                'region' => "Region $index",
                'country' => $faker->country,
                'specialty' => $faker->jobTitle,
                'interests' => $faker->sentence,
                'phone' => $faker->phoneNumber,
            ]);
        }

        //Seed Doctors MT Users
        $doctorIds = [];
        foreach (Doctor::all() as $index => $doctor) {
            $user = UserMT::create([
                'full_name' => $doctor->full_name,
                'email' => $doctor->email,
                'gender' => $faker->randomElement(['male', 'female']),
                'birth_date' => $faker->date,
                'specialty' => $doctor->specialty,
                'interests' => $doctor->interests,
                'phone' => $doctor->phone,
                'place_of_employment' => $faker->company,
                'registration_date' => $faker->date,
                'country' => $doctor->country,
                'region' => $doctor->region,
                'city' => $doctor->city,
                'registration_website' => $faker->url,
                'acquisition_tool' => "some tool $index",
                'acquisition_method' => "some method $index",
                'uf_utm_term' => "uf_utm_term_$index",
                'uf_utm_campaign' => "uf_utm_campaign$index",
                'uf_utm_content' => "uf_utm_content$index",
            ]);
            $doctorIds[] = $user->id;
        }

        //Seed Common Database Doctors Users MT info
        foreach (UserMT::whereIn('id', $doctorIds)->get() as $index => $mtUser) {
            CommonDatabase::create([
                'email' => $mtUser->email,
                'full_name' => $mtUser->full_name,
                'city' => $mtUser->city,
                'region' => $mtUser->region,
                'country' => $mtUser->country,
                'specialty' => $mtUser->specialty,
                'interests' => $mtUser->interests,
                'phone' => $mtUser->phone,
                'mt_user_id' => $mtUser->id,
                'registration_date' => $mtUser->registration_date,
                'gender' => $mtUser->gender,
                'birth_date' => $mtUser->birth_date,
                'registration_website' => $mtUser->registration_website,
                'acquisition_tool' => $mtUser->acquisition_tool,
                'acquisition_method' => $mtUser->acquisition_method,
                'planned_actions' => $index,
                'resulting_actions' => $index,
                'verification_status' => "ok$index",
                'pharma' => $faker->boolean,
                'email_status' => $faker->boolean ? 'yes' : 'no',
            ]);
        }

        //Seed Activities MT
        foreach (range(1, 20) as $index) {
            ActivityMT::create([
                'type' => $faker->word,
                'name' => $faker->sentence,
                'date_time' => $faker->dateTime,
                'is_online' => $faker->boolean,
            ]);
        }

        //Seed Actions MT
        foreach (UserMT::all() as $mtUser) {
            foreach (ActivityMT::all() as $activity) {
                ActionMT::create([
                    'mt_user_id' => $mtUser->id,
                    'activity_id' => $activity->id,
                    'date_time' => $faker->dateTime,
                    'duration' => $faker->randomFloat(2, 1, 5),
                    'result' => $faker->randomFloat(2, 0, 100),
                ]);
            }
        }

        //Seed Parsing PD
        foreach (UserMT::all() as $mtUser) {
            ParsingPD::create([
                'mt_user_id' => $mtUser->id,
                'difference' => $faker->word,
                'pd_workplace' => $mtUser->place_of_employment,
                'pd_address_workplace' => $faker->address,
            ]);
        }

        //Seed Pharma
        foreach (UserMT::whereNotIn('id', $doctorIds)->get() as $mtUser) {
            Pharma::create([
                'domain' => $mtUser->email,
                'name' => $faker->company,
            ]);
        }

        //Seed Unisender Contacts
        foreach (CommonDatabase::all() as $data) {
            UnisenderContact::create([
                'email' => $data->email,
                'contact_status' => $faker->randomElement(['active', 'inactive']),
                'email_status' => $faker->randomElement(['verified', 'unverified']),
                'email_availability' => $faker->boolean ? 'yes' : 'no',
            ]);
        }

        //Seed Unisender Campaigns
        foreach (range(1, 20) as $index) {
            UnisenderCampaign::create([
                'campaign_name' => $faker->sentence,
                'send_date' => $faker->dateTime,
                'open_rate' => $faker->randomFloat(2, 0, 1),
                'ctr' => $faker->randomFloat(2, 0, 1),
                'sent' => $faker->numberBetween(100, 1000),
                'delivered' => $faker->numberBetween(100, 1000),
                'delivery_rate' => $faker->randomFloat(2, 0, 1),
                'opened' => $faker->numberBetween(50, 500),
                'open_per_unique' => $faker->numberBetween(1, 100),
                'clicked' => $faker->numberBetween(10, 200),
                'clicks_per_unique' => $faker->numberBetween(1, 50),
                'ctor' => $faker->randomFloat(2, 0, 1),
            ]);
        }

        //Seed Unisender Participation
        $contactsEmails = UnisenderContact::pluck('email')->unique()->toArray();
        foreach (UnisenderCampaign::all() as $campaign) {
            foreach (range(1, 10) as $index) {
                UnisenderParticipation::create([
                    'campaign_id' => $campaign->id,
                    'email' => $contactsEmails[array_rand($contactsEmails)],
                    'result' => $faker->boolean ? 'yes' : 'no',
                    'update_time' => $faker->dateTime,
                ]);
            }
        }
    }
}
