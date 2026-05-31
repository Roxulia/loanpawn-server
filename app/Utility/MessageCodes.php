<?php

namespace App\Utility;

/*
* sb = success message in backend
* cb = confirm message in backend
* ib = info message in backend
* wb = error message in backend
* eb = error message in backend
*/

class MessageCodes
{
    public static $messages=array(
        "eb001" => "Tenant not found.",
        "eb002" => "No Tenant Code Found in Header",
        "eb003" => "Invalid Email or Password",
        "eb004" => "Account Not Found",
        "eb005" => "Duplicate value found.",
        "eb006" => "Required value is missing.",
        "eb007" => "No User Logged In",
        "eb008" => "Platform user id is required.",
        "eb009" => "Plan type is required.",
        "eb010" => "Status is required.",
        "eb011" => "Tenant code already exists.",
        "eb012" => "Subdomain already exists.",
        "eb013" => "Uploaded file is invalid.",
        "eb014" => "Uploaded image is invalid.",
        "eb015" => "Stored file not found.",
        "eb016" => "Tenant request is invalid.",
        "eb017" => "Only platform users can create tenant requests.",
        "eb018" => "Tenant does not belong to current platform user.",
        "eb019" => "Upgrade target plan is invalid.",
        "eb020" => "Extension duration is invalid.",
        "eb021" => "Trial package cannot be extended.",
        "eb022" => "Payment screenshot can only be submitted for waiting payment requests.",
        "eb023" => "Tenant request was already processed.",
        "eb024" => "Only tenant admin users or the tenant owner platform user can perform this action.",
        "eb025" => "Tenant user not found.",
        "eb026" => "This tenant user can update only their own profile.",
        "eb027" => "Tenant role not found.",
        "eb028" => "Tenant customer not found."
    );

}
