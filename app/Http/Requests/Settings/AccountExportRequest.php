<?php

namespace App\Http\Requests\Settings;

/**
 * "Download your data" confirms it's you exactly as deleting the account
 * does: the password, or for an account made with Google the code just
 * emailed to it (EXP-174, #195).
 */
class AccountExportRequest extends ProfileDeleteRequest {}
