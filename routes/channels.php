<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use App\Models\SOS;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Channel spesifik per-relawan (untuk penugasan SOS langsung ke 1 relawan)
Broadcast::channel('relawan.{id}', function (User $user, $id) {
    return $user->role === 'relawan';
});

// Channel umum semua relawan (untuk broadcast SOS ke semua relawan)
Broadcast::channel('relawan-channel', function (User $user) {
    return $user->role === 'relawan'; 
});

// Channel per-SOS (untuk tracking lokasi & update status)
Broadcast::channel('sos.{sosId}',function (User $user, $sosId) {
    $sos = SOS::find($sosId);
    if(!$sos){
        return false;
    }

    return $user->id === $sos->id_pengguna || $user->role === 'relawan'; //hanya pembuat sos dan relawan
});
