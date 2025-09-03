<?php
function env($k,$def=null){$v=getenv($k);return($v===false||$v==='')?$def:$v;}
