#!/bin/bash
export PGPASSWORD='admin'
export PAGER='less -S'
if ! psql -h localhost -p 5432 -U admin -d shops; then
  echo ""
  echo "Failed to connect to the database. Is the docker instance running?"
  exit 1
fi
