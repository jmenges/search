Fork of the CakeDC Search Plugin with Regex Modelfields
========================

A possible element in a given Model $filterArgs could now be:
  'test[0-9]+' => [
    'type' => 'finder',
    'finder' => 'ignore'
  ]
  
The query data is than parsed by regex and given fields are matched.
