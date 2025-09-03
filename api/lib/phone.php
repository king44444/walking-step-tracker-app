<?php
function to_e164($raw){
  $d=preg_replace('/\D+/','',$raw??'');
  if($d==='')return null;
  if(strlen($d)===11 && $d[0]==='1')$d=substr($d,1);
  if(strlen($d)===10)return '+1'.$d;
  if($raw && $raw[0]==='+')return '+'.$d;
  return null;
}
