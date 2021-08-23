<?php

function f1(){
    static $a = array(1,2,3);


    print_r($a);

    static $a = array();
    print_r($a);
}

function f2(){
    static $a = array();
    $a[] = 1;
    $a[] =2 ;
    $a[] = 3;
    print_r($a);

    static $a = array();
    print_r($a);
}
f1();

echo "<br>";
f2();

echo "<br>";

function get_count(){
    static $count = 0;
    return $count++;
}
echo get_count();

echo "<br>";

echo get_count();


function f3(){
    $tempature = 18;
    static $tempature = 25;

    if($tempature <= 20){
        echo "凉爽";
    }else if($tempature >= 28){
        echo "太热了";
    }else{
        echo "刚刚好";
    }
    echo "<br>";
}
f3();



