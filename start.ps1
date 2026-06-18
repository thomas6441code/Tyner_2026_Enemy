# EAPMS — start all services via yner_main (Windows)
Set-Location "$PSScriptRoot\yner_main"
php artisan eapms:start @args
