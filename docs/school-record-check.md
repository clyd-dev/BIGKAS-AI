# School records to check (used for the DepEd Form 2 header)

Source: `schools` table of the `bigkas_ai` database, read on 2026-10-07.
Form 2 prints: **School, Division, District, Region** (and the principal for the signature line).

| id | School | School ID | District | Division | Region | Principal | Status | Sections (classes) |
|----|--------|-----------|----------|----------|--------|-----------|--------|--------------------|
| 1 | Old Sagay Elementary School | SCH-001 | Sagay City | Negros Occidental | Region VI | Dr. Maria Santos | active | 4 |

**Update 2026-10-07:** rows 2 to 4 were deleted at your request; only row 1 remains. The 6 users and 2 learners
that pointed to them were moved to row 1. `SchoolSeeder` was trimmed so a re-seed does not recreate them.

## Things to confirm

1. The Form 2 header prints row 1: *Old Sagay Elementary School, Sagay City district, Negros Occidental division,
   Region VI*, principal *Dr. Maria Santos*.
2. **Division / District wording:** Phil-IRI Form 2 labels these "Sangay/Division", "Distrito/District", "Rehiyon/Region".
   In the sample form the Division is a city division (e.g. "Lungsod ng Davao"). Is "Negros Occidental" the right
   division for your school, or should it be "Sagay City"?
3. **School ID:** the stored values look like placeholders (`SCH-001`). The official DepEd School ID is a 6-digit
   number. Form 2 does not print it, but other DepEd forms do. Do you want to replace it?

How to fix a value: Admin panel -> Schools.
