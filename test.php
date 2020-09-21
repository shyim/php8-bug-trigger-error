<?php

for ($i = 0; $i < 50000; $i++) {
    @trigger_error(
        'Calling Test() with any arguments to flush specific entities is deprecated and will not be supported in Doctrine ORM 3.0.' . uniqid('asd', true),
        E_USER_DEPRECATED
    );
}