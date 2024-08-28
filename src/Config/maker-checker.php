<?php

declare(strict_types=1);

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

return [
    /*
     * This configuration is to determine whether you want to have the package run checks for whether a request already
     * exists before creating one. If set to false, duplicate requests (with similar payload/subjects) would be allowed.
     */
    'ensure_requests_are_unique' => true,
    /*
     * The time, in minutes, at which point a pending request is marked as expired.
     * If left as null, pending requests would be allowed to stay as long as possible till an action is performed on them.
     */
    'request_expiration_in_minutes' => null,

    /*
     * This configuration is for the purpose of limiting the actions of making/checking requests to certain models.
     * If it is left empty, any model will be able to initiate/approve/decline a request.
     */
    'whitelisted_models' => [
        'maker' => [], //e.g [User::class]
        'checker' => [], //e.g [Admin::class]
    ],
    /**
     * This configuration is fr users that are allowed to be both the maker and the checker of a request.
     * If a user is not in this list, they cannot be both the maker and the checker of a request.
     */
    'whitelisted_emails' => env('MAKER_CHECKER_WHITELISTED_EMAILS', ''),

    // The model attached to the table for storing requests.
    'request_model' => MakerCheckerRequest::class,

    // The table that will be created by the published migration and that will be attached to the request model.
    'table_name' => 'maker_checker_requests',
];
