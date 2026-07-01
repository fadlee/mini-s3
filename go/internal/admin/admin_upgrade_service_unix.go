//go:build !windows

package admin

import (
	"os/exec"
	"syscall"
)

// setDetach configures the command to run in a new process group so it
// survives when the current process exits.
func setDetach(cmd *exec.Cmd) {
	if cmd.SysProcAttr == nil {
		cmd.SysProcAttr = &syscall.SysProcAttr{}
	}
	cmd.SysProcAttr.Setpgid = true
}
