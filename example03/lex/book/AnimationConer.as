package lex.book 
{
	import flash.display.*;
	import flash.events.MouseEvent;
	import flash.geom.Point;
	import flash.geom.Rectangle;

	import lex.geom.V2;
	/**
	 * ...
	 * @author Lexcuk
	 */
	public class AnimationConer
	{
		public var twoRectSp:Sprite;
		private var dropPage:DropPage;
		private var s:BookStyle;
		private var delim:Number = 7;
		private var cutOffTr:Boolean = true;
		public var leftTr:Boolean;
		private var nav:PageNavigator;
		private var slowing:Slowing;
		private var fixMouse:FixMouse;
		private var mousePlusTr:Boolean, parkingTr:Boolean, 
		urgeTr:Boolean;//датчик перехода на новую страницу
		public var callBackContentRestoreAction:Function;
		public var animaTimeOutTr:Boolean;
		public var pressedTr:Boolean;
		public var callBackRevisionMouseControl:Function;
		public var slowingSpeedConer:Number;
		public var slowingSpeedParking:Number;
		public var imBusy:Boolean = true;
		public var curAniV2:V2 = new V2();
		public function AnimationConer(dropPage:DropPage,nav:PageNavigator) 
		{
			twoRectSp = new Sprite();
			this.dropPage = dropPage;
			this.nav = nav;
			fixMouse = dropPage.fixMouse;
			s = dropPage.s;
			slowing = new Slowing(slowingSpeedConer = s.width/20);
			slowingSpeedParking = s.width / 7;
			slowing.callBack = slowingCall;
			
			twoRectSp.graphics.beginFill(0,0);
			twoRectSp.graphics.drawRect(s.bl.x + 3, s.bl.y - s.height / delim - 3, s.width / delim, s.height / delim);
			
			twoRectSp.graphics.drawRect(s.br.x - 3 - s.width / delim, s.br.y - s.height / delim - 3, s.width / delim, s.height / delim);
			
			twoRectSp.name = 'twoRectSp';
			
			
			twoRectSp.addEventListener(MouseEvent.ROLL_OVER, twoRectSpOver);
			twoRectSp.addEventListener(MouseEvent.MOUSE_DOWN, twoRectSpDownHandler);
			twoRectSp.addEventListener(MouseEvent.MOUSE_UP, twoRectSpUpHandler);
		}

		private function twoRectSpDownHandler(e:MouseEvent):void {
			pressedTr = true;
		}

		private function twoRectSpUpHandler(e:MouseEvent):void {
			pressedTr = false;
		}

		public function force():void {
			twoRectSpOut();
		}

		private function twoRectSpOut(e:MouseEvent = null):void {//выход
			//trace('twoRectSpOut');
			twoRectSp.removeEventListener(MouseEvent.MOUSE_MOVE, twoRectSpMove);
			twoRectSp.removeEventListener(MouseEvent.CLICK, twoRectSpClick);
			twoRectSp.removeEventListener(MouseEvent.MOUSE_OUT, twoRectSpOut);
			parkingTr = true;
			urgeTr = false;
			animaTimeOutTr = false;
			if (pressedTr) {
				
				parkingTr = false;
				if (callBackRevisionMouseControl!=null) callBackRevisionMouseControl();
			}else{
				if (mousePlusTr) slowing.setCoord(dropPage.s.width, dropPage.s.height);
				if (!mousePlusTr) slowing.setCoord( -dropPage.s.width, dropPage.s.height);
			}
		}


		private function twoRectSpOver(e:MouseEvent = null):void {//вход
			//if (parkingTr) return;
			pressedTr = false;
			animaTimeOutTr = false;
			slowing.speed = slowingSpeedConer;
			//trace('twoRectSpOver');
			if (!parkingTr){
				if (twoRectSp.mouseX > 0) {
					leftTr = false;
					mousePlusTr = true;
					nav.aim = nav.cur + 1;
					if (nav.aim > nav.max) return;
					dropPage.dropShow(true,true);
					slowing.setCurrent(dropPage.s.width, dropPage.s.height);
					if (callBackContentRestoreAction != null) callBackContentRestoreAction();
					
				}else {
					leftTr = true;
					mousePlusTr = false;
					nav.aim = nav.cur - 1;
					if (nav.aim < 1) return;
					dropPage.dropShow(true,true);
					slowing.setCurrent(-dropPage.s.width, dropPage.s.height);
					if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				}
				twoRectSp.addEventListener(MouseEvent.MOUSE_MOVE, twoRectSpMove);
				twoRectSp.addEventListener(MouseEvent.CLICK, twoRectSpClick);
				twoRectSp.addEventListener(MouseEvent.MOUSE_OUT, twoRectSpOut);
				//dropPage.masterSp.stage.addEventListener(MouseEvent.MOUSE_UP, masterSpUpHandler);
				//masterSpMoveHandler();
			}
			
		}

		private function twoRectSpClick(e:MouseEvent):void {
			twoRectSp.removeEventListener(MouseEvent.MOUSE_MOVE, twoRectSpMove);
			twoRectSp.removeEventListener(MouseEvent.CLICK, twoRectSpClick);
			twoRectSp.removeEventListener(MouseEvent.MOUSE_OUT, twoRectSpOut);
			urgeTr = true;
			parkingTr = true;
			animaTimeOutTr = true;
			imBusy = true;
			if (!mousePlusTr) slowing.setCoord(dropPage.s.width, dropPage.s.height);
			if (mousePlusTr) slowing.setCoord( -dropPage.s.width, dropPage.s.height);
			if (callBackContentRestoreAction != null) callBackContentRestoreAction();
		}

		private function twoRectSpMove(e:MouseEvent = null):void {
			var coord:V2 = fixMouse.fix(new V2(twoRectSp.mouseX, twoRectSp.mouseY));
			var twoRectPoint:Point;
			
			
			
			twoRectPoint = new Point(twoRectSp.mouseX, twoRectSp.mouseY);
			
			twoRectPoint = twoRectSp.localToGlobal(twoRectPoint);
			
			
			if (twoRectSp.hitTestPoint(twoRectPoint.x, twoRectPoint.y, true)) {
				slowing.setCoord(coord.x, coord.y);
			}
		}

		private function slowingCall():void {
			var reactionTr:Boolean = true;
			var twoRectPoint:Point;
			var primareLeftTr:Boolean;
			if (parkingTr) {
				if (animaTimeOutTr) {
					//if (slowing.curV2.x==
					slowing.setTimeFin(0, s.height / 2);
					animaTimeOutTr = false;
				}
				
			}
			if (urgeTr) slowing.speed = slowingSpeedParking; else slowing.speed = slowingSpeedConer;
			if (parkingTr && slowing.doneTr) {
				imBusy = false;
				if (urgeTr) {
					
					nav.cur = nav.aim;
					dropPage.dropShow(false,true);
				}else {
					
					nav.aim = nav.cur;
					dropPage.dropShow(false, true);
				}
				if (callBackContentRestoreAction != null) callBackContentRestoreAction();
				parkingTr = false;
				twoRectPoint = new Point(twoRectSp.mouseX, twoRectSp.mouseY);
				
				twoRectPoint = twoRectSp.localToGlobal(twoRectPoint)//.subtract(twoRectPoint);
				
				//trace('перелстывание завершилось' + twoRectSp.hitTestPoint(twoRectPoint.x, twoRectPoint.y, false));
				
				if (urgeTr && twoRectSp.hitTestPoint(twoRectPoint.x, twoRectPoint.y, true)) {
					reactionTr = false;
					parkingTr = false;
					if (twoRectSp.mouseX > 0 && !leftTr) primareLeftTr = false; else
						primareLeftTr = true;
						
					if (primareLeftTr == leftTr) {
						if (s.nav.aim<s.nav.max){
							twoRectSpOver();
							twoRectSpMove();
						}
					}
				}
				
			}else
			if (reactionTr) dropPage.action(slowing.curV2.x, slowing.curV2.y);
			curAniV2.copy(slowing.curV2);
		}

	}

}