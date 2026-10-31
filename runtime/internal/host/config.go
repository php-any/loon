package host

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
)

// 项目配置只定义宿主入口和资源位置；业务配置归应用所有。
type projectConfig struct {
	TCP      *protocolConfig `json:"tcp,omitempty"`
	GRPC     *protocolConfig `json:"grpc,omitempty"`
	Address  string          `json:"address"`
	HTTP     string          `json:"http"`
	Console  string          `json:"console"`
	Jobs     string          `json:"jobs"`
	Database string          `json:"database"`
}

func readConfig(path string) (projectConfig, error) {
	var config projectConfig
	file, err := os.Open(path)
	if err != nil {
		return config, fmt.Errorf("读取项目配置：%w", err)
	}
	defer file.Close()
	decoder := json.NewDecoder(file)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&config); err != nil {
		return config, fmt.Errorf("解析项目配置：%w", err)
	}
	var extra any
	if err := decoder.Decode(&extra); err != io.EOF {
		return config, fmt.Errorf("项目配置必须是单个 JSON 对象")
	}
	if config.Address == "" || config.HTTP == "" || config.Console == "" || config.Jobs == "" || config.Database == "" {
		return config, fmt.Errorf("项目配置缺少监听地址、入口或数据库路径")
	}
	for _, protocol := range []*protocolConfig{config.TCP, config.GRPC} {
		if protocol != nil && (protocol.Address == "" || protocol.Entry == "") {
			return config, fmt.Errorf("协议配置必须包含 address 和 entry")
		}
	}
	if config.GRPC != nil && (len(config.GRPC.ImportPaths) == 0 || len(config.GRPC.ProtoFiles) == 0) {
		return config, fmt.Errorf("gRPC 配置必须包含 import_paths 和 proto_files")
	}
	return config, nil
}
