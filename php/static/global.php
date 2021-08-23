<?php

function t1()
{
    t2();
    echo 111;
    global $b;
    echo $b;
}

function t2()
{
    global $b;
    $b=2;
}

t1();