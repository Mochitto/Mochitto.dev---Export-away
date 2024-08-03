start: 
	@make headless
	@echo "🤖🤖🤖 Connecting to the app's logs..."
	@echo "Press 'q' to exit and stop docker containers, 'Ctrl+C' to leave the containers running."
	@echo
	@bash -c 'docker compose logs -f app & \
	LOGS_PID=$$!; \
	while : ; do \
		read -n 1 -r -s key; \
		if [ "$$key" = "q" ] || [ "$$key" = "Q" ]; then \
			break; \
		fi; \
	done; \
	kill $$LOGS_PID && make stop'

headless: 
	@make stop
	@clear
	@echo "🤖🤖🤖 Bringing up development containers!"
	@echo
	@docker compose up -d
	@echo
	@echo "🐢🐢🐢 Containers ready! You are using 🔨development🔨 mode."
	@echo "❗️❗️❗️ The containers will keep running in the background; remember to shut them down"
	@echo

stop:
	@echo
	@echo "💣💣💣 Stopping the containers..."
	@docker compose down
	@echo
	@echo "🤖🤖🤖 Docker stopped."
