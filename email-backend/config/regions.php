<?php

declare(strict_types=1);

/**
 * Region code => the inbox that receives enquiries for that region.
 *
 * This is the only place recipients are defined. The frontend sends a region
 * code and never an address. To add a region, add a line here.
 *
 * Replace these placeholders before going live: the API refuses to send to an
 * @example.com address, so a forgotten placeholder fails visibly instead of
 * silently losing a customer's enquiry.
 */

return [
    'PK' => 'sardarumair@nexgencommercialbrokers.com',
    'UAE' => 'sardarumair@nexgencommercialbrokers.com',
    'MY' => 'sardarumair@nexgencommercialbrokers.com',
    'OTHER' => 'sardarumair@nexgencommercialbrokers.com',
];
