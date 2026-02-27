<?php
return array (
  'admin' => 
  array (
    'username' => 'admin',
    'password' => 'admin123',
  ),
  'gacha' => 
  array (
    'pools' => 
    array (
      'common' => 
      array (
        'name' => '所有，或者一无所有',
        'cost' => 100,
        'color' => '#9ca3af',
        'min_reward' => 0,
        'max_reward' => 300,
        'probability' => 50,
      ),
      'rare' => 
      array (
        'name' => '银河大乐透',
        'cost' => 200,
        'color' => '#3b82f6',
        'min_reward' => 150,
        'max_reward' => 300,
        'probability' => 30,
      ),
      'epic' => 
      array (
        'name' => '赌鬼出现了',
        'cost' => 400,
        'color' => '#8b5cf6',
        'min_reward' => 1000,
        'max_reward' => 2000,
        'probability' => 10,
      ),
      'legendary' => 
      array (
        'name' => '赢下所有',
        'cost' => 1000,
        'color' => '#f59e0b',
        'min_reward' => 2000,
        'max_reward' => 4000,
        'probability' => 7,
      ),
      'mythic' => 
      array (
        'name' => '奇迹',
        'cost' => 1000,
        'color' => '#ec4899',
        'min_reward' => 4000,
        'max_reward' => 8000,
        'probability' => 3,
      ),
    ),
    'special_events' => 
    array (
      'enabled' => true,
      'double_probability' => 5,
      'jackpot_multiplier' => 10,
      'consolation_prize' => 10,
    ),
  ),
);
?>