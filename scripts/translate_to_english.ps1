$replacements = @{
  'EL FIRMA organise tout le travail agricole de la saison'='EL FIRMA organizes all seasonal agricultural work'
  'Production agricole saine, tracee et de qualite'='Healthy, traceable, high-quality agricultural production'
  'Une agriculture organisee, productive et durable'='Organized, productive, and sustainable agriculture'
  'Planification, execution et suivi de chaque parcelle'='Planning, execution, and monitoring for every plot'
  'Des recoltes utiles pour tous'='Harvests that matter for everyone'
  'EL FIRMA organise la planification, le suivi et l execution des travaux agricoles du semis a la commercialisation.'='EL FIRMA plans, monitors, and executes agricultural operations from sowing to commercialization.'
  'Pourquoi EL FIRMA'='Why EL FIRMA'
  'Suivi terrain, qualite et coordination quotidienne.'='Field monitoring, quality control, and daily coordination.'
  'Une organisation agricole qui fait la difference'='An agricultural organization that makes the difference'
  'EL FIRMA organise et coordonne l ensemble des operations agricoles :'='EL FIRMA organizes and coordinates all agricultural operations:'
  'planification des travaux, suivi des equipes, controle qualite,'='work planning, team supervision, quality control,'
  'logistique des intrants et supervision des recoltes.'='input logistics, and harvest supervision.'
  'Planification claire de chaque campagne'='Clear planning for every season'
  'Suivi terrain en temps reel'='Real-time field monitoring'
  'Coordination des equipes et des ressources'='Team and resource coordination'
  'Contactez EL FIRMA'='Contact EL FIRMA'
  'Organisation, suivi et qualite pour chaque saison'='Organization, monitoring, and quality for every season'
  '“EL FIRMA nous aide a planifier les travaux et a mieux suivre'='“EL FIRMA helps us plan tasks and better monitor'
  'chaque etape de notre production agricole.”'='every step of our agricultural production.”'
  '“Le suivi organise par EL FIRMA nous fait gagner du temps'='“The monitoring organized by EL FIRMA saves us time'
  'et ameliore la qualite des operations sur le terrain.”'='and improves the quality of field operations.”'
  '“Avec EL FIRMA, nous avons une meilleure coordination des'='“With EL FIRMA, we have better coordination of'
  'equipes, des ressources et des interventions agricoles.”'='teams, resources, and agricultural interventions.”'
  '“EL FIRMA apporte une vraie rigueur dans la planification,'='“EL FIRMA brings real rigor to planning,'
  'le suivi et la reussite des campagnes agricoles.”'='monitoring, and successful seasonal campaigns.”'
  'Recevez les actualites EL FIRMA'='Get EL FIRMA updates'
  'Abonnez-vous pour recevoir nos nouvelles, campagnes'='Subscribe to receive our latest news, seasonal campaigns,'
  'et conseils agricoles.'='and agricultural advice.'
  'Votre demande d inscription a ete envoyee. Merci !'='Your subscription request has been sent. Thank you!'
  'EL FIRMA accompagne les exploitations en organisant les travaux, les equipes et le suivi des campagnes agricoles.'='EL FIRMA supports farms by organizing operations, teams, and seasonal campaign monitoring.'
  'EL FIRMA est a votre ecoute pour organiser, suivre et optimiser tous vos travaux agricoles.'='EL FIRMA is here to help you organize, monitor, and optimize all your agricultural operations.'
  'Votre message a ete envoye. Merci !'='Your message has been sent. Thank you!'
  'Subcribe'='Subscribe'
}

$files = Get-ChildItem -Path templates -Recurse -Filter *.twig | Select-Object -ExpandProperty FullName

foreach ($file in $files) {
  $content = Get-Content -Raw -Path $file
  $updated = $content

  foreach ($key in $replacements.Keys) {
    $updated = $updated.Replace($key, $replacements[$key])
  }

  if ($updated -ne $content) {
    Set-Content -Path $file -Value $updated -Encoding UTF8
    Write-Output "Updated: $file"
  }
}
