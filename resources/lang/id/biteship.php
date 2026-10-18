<?php

declare(strict_types=1);

return [
    'timeline' => [
        'booked' => 'Penjemputan dipesan lewat Biteship (:courier)',
        'booking_failed' => 'Pemesanan Biteship gagal: :message',
        'status' => 'Biteship: :status',
    ],
    'status' => [
        'confirmed' => 'Dipesan, mencari kurir',
        'allocated' => 'Kurir ditemukan, menunggu penjemputan',
        'picking_up' => 'Kurir menuju lokasi penjemputan',
        'picked' => 'Sudah dijemput',
        'in_transit' => 'Dalam perjalanan',
        'dropping_off' => 'Sedang diantar',
        'return_in_transit' => 'Dikembalikan ke toko',
        'on_hold' => 'Ditahan',
        'delivered' => 'Terkirim',
        'rejected' => 'Ditolak Biteship',
        'courier_not_found' => 'Tidak ada kurir tersedia',
        'returned' => 'Dikembalikan',
        'cancelled' => 'Dibatalkan',
        'disposed' => 'Dimusnahkan',
    ],
];
