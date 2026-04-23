# Vaccination Domain - Evidence Aligned Guide

## Scope
This guide documents vaccination data conservatively, without promoting unverified workflows as canon.

## confirmed_from_screenshot
- Table exists: vaccinations.
- Confirmed fields: id_vaccination, vaccine_name, date_done, date_next, notes, status, id_animal.
- Observed sample values (not exhaustive):
  - vaccine_name: Brucellosis
  - status: Scheduled

## inferred_from_schema
- vaccinations.id_animal likely links vaccination records to animals.id_animal.
- date_done and date_next likely support historical and planning views.
- notes likely stores contextual observations entered by staff.

## assumed_business_rule
No official status lifecycle is confirmed from screenshots beyond observed sample value Scheduled.

Do not treat undocumented labels as canonical.

Hypothetical workflow examples (require validation):
- date_next may be used to prepare upcoming tasks.
- date_done may indicate completed administration date.
- status labels may vary across teams and periods.

## Practical Questions Supported By Current Evidence
- What fields are available in vaccination records?
- Which vaccination status value is currently observed? (Scheduled)
- Is Scheduled the only possible status? (unknown, not exhaustive)
- How are vaccination records connected to animals?

## Multilingual Notes
- Keep French schema names in data references.
- Support bilingual query wording, for example vaccination status and statut de vaccination.
