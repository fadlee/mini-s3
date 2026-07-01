//go:build windows

package admin

import (
	"os/exec"
	"syscall"
)

// setDetach configures the command to start in a new process group so it
// survives when the current process exits. On Windows the running binary
// cannot be overwritten, so the upgrade renames it first and the new process
// takes over after the old one exits.
func setDetach(cmd *exec.Cmd) {
	if cmd.SysProcAttr == nil {
		cmd.SysProcAttr = &syscall.SysProcAttr{}
	}
	// CREATE_NEW_PROCESS_GROUP (0x00000200) | DETACHED_PROCESS (0x00000008)
	cmd.SysProcAttr.CreationFlags = 0x00000200 | 0x00000008
}
