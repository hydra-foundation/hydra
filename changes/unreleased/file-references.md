---
section: The admin
kind: added
---
`FileReferences::all()` reports every row that points at a stored file: the module, the row, the column, and the name kept beside the key. It reads every file control and every `Field::image()` or `Field::file()` column through each module's own source, with no row cap. Files kept anywhere else are declared by a `FileHolderInterface`, which a module's source can implement to be asked instead of walked. `Field::nameColumn()` returns the column `nameFrom()` names.
