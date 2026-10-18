<?php

declare(strict_types=1);

return [
    'timeline' => [
        'booked' => 'Pickup booked with Biteship (:courier)',
        'booking_failed' => 'Biteship booking failed: :message',
        'status' => 'Biteship: :status',
    ],
    'status' => [
        'confirmed' => 'Booked, finding a courier',
        'allocated' => 'Courier assigned, waiting to pick up',
        'picking_up' => 'Courier on the way to pick up',
        'picked' => 'Picked up',
        'in_transit' => 'In transit',
        'dropping_off' => 'Out for delivery',
        'return_in_transit' => 'Returning to the store',
        'on_hold' => 'On hold',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected by Biteship',
        'courier_not_found' => 'No courier available',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
        'disposed' => 'Disposed',
    ],
];
