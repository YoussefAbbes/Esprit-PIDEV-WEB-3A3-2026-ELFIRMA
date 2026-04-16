# Livestock Domain (Elevage) - Evidence Aligned Guide

## Scope
This guide focuses on the elevage domain with strict separation between evidence and assumptions.

## confirmed_from_screenshot
- Table exists: elevage.
- Confirmed fields: id_elevage, type_elevage, etat_elevage, capacite, nombre_animaux, production, latitude, longitude.
- Observed sample values (not exhaustive):
  - type_elevage: Poultry Farm, Sheep Farm, Bovin Farm
  - etat_elevage: Cleaning, Under Cleaning
  - production: Egg, Milk

## inferred_from_schema
- elevage likely represents a livestock unit or farm unit context.
- animals.id_elevage likely references elevage.id_elevage.
- capacite and nombre_animaux likely support occupancy monitoring.
- latitude and longitude likely support mapping and geolocation-based views.

## assumed_business_rule
No official severity framework for etat_elevage is confirmed.

Important correction:
- Do not treat labels such as stable/warning/critical as canonical unless confirmed by owner or DDL/business docs.
- Current observed labels (Cleaning, Under Cleaning) must be treated as sample values only.

Hypothetical examples (not policy):
- Teams may use etat_elevage for maintenance or operational activity states.
- Teams may compute occupancy indicators from capacite and nombre_animaux.

## Monitoring Questions Supported By Current Evidence
- What are the confirmed fields of elevage?
- Which etat_elevage values were observed in screenshots?
- Is the observed value list exhaustive? (answer: no)
- How does elevage connect to animals?

## Multilingual Notes
- Preserve French field names in prompts and answers.
- Accept both elevage and livestock wording in user queries.
