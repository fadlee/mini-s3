package auth

import "fmt"

// Exception is the Go equivalent of MiniS3\Auth\AuthException. It carries
// an S3 error code and an HTTP status alongside the error message.
type Exception struct {
	S3Code     string
	Message    string
	HTTPStatus int
}

func (e *Exception) Error() string {
	return fmt.Sprintf("%s: %s", e.S3Code, e.Message)
}

// NewException creates an auth Exception with the default HTTP status 401.
func NewException(s3Code, message string) *Exception {
	return &Exception{S3Code: s3Code, Message: message, HTTPStatus: 401}
}

// NewExceptionWithStatus creates an auth Exception with a custom HTTP status.
func NewExceptionWithStatus(s3Code, message string, httpStatus int) *Exception {
	return &Exception{S3Code: s3Code, Message: message, HTTPStatus: httpStatus}
}
