---
section: The admin
kind: added
---
`FileReferences` reports which rows point at which stored files. It reads every file control and every `Field::image()` or `Field::file()` column through each module's own source, with no row cap. Files kept anywhere else are declared by a `FileHolderInterface`. An application lists its own in the `AdminServiceProvider`'s new `fileHolders:` argument, and a module's source can implement it to be asked instead of walked. `to()` returns what points at one file: the module, the row, the column and the kept name. `orphans()` returns the files on either disk that nothing points at and that are at least a day old. It reads every reference before looking at a file, and throws if any module or holder cannot be read. `Field::nameColumn()` returns the column `nameFrom()` names.
