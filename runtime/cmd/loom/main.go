package main

import (
	"os"

	"github.com/php-any/loom/runtime/internal/host"
)

func main() { os.Exit(host.Run(os.Args[1:])) }
