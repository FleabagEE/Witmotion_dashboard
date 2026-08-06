<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forwarder metrics
    |--------------------------------------------------------------------------
    |
    | Written by the acquisition-side forwarder every batch. The dashboard reads
    | it to answer a question neither the sensors nor the structure can: are the
    | readings reaching the database, and if not, are they safe on disk.
    |
    | A path rather than a database query on purpose. The spool is SQLite owned
    | by another service account, and a second writer would be a lock to argue
    | over. A stale file is itself the signal that the forwarder has stopped.
    |
    */

    'forwarder_metrics' => env(
        'QUAKEVAULT_FORWARDER_METRICS',
        '/var/lib/quakevault-acq/forwarder.prom',
    ),

];
